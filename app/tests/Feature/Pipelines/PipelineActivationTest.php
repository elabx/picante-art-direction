<?php

use App\Enums\FieldRole;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Services\Pipelines\PipelineActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function readyPipeline(PipelineKind $kind, ?Campaign $campaign = null): Pipeline
{
    $pipeline = Pipeline::factory()->create([
        'kind' => $kind,
        'input_schema' => ['properties' => ['image' => ['type' => 'string'], 'prompt' => ['type' => 'string']]],
    ]);

    if ($kind === PipelineKind::Editor) {
        PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
        PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt]);
    }

    if ($kind === PipelineKind::Upscaler) {
        PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
    }

    if ($campaign !== null) {
        attachPipeline($campaign, $pipeline);
    }

    return $pipeline;
}

it('refuses to mark ready a pipeline that is not ready', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => null]);

    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))
        ->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_ready)->toBeFalse();
});

it('marks multiple catalog editors ready under a pipeline row lock', function (): void {
    $sqls = [];
    DB::listen(function ($query) use (&$sqls): void {
        $sqls[] = $query->sql;
    });
    $campaign = Campaign::factory()->create();
    $first = readyPipeline(PipelineKind::Editor, $campaign);
    $second = readyPipeline(PipelineKind::Editor, $campaign);

    app(PipelineActivation::class)->markReady($first);

    app(PipelineActivation::class)->markReady($second);

    expect($campaign->pipelines()->where('is_ready', true)->count())->toBe(2)
        ->and(collect($sqls)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'for update')))->toBeTrue();
});

it('allows one active editor in each campaign', function (): void {
    $first = readyPipeline(PipelineKind::Editor);
    $second = readyPipeline(PipelineKind::Editor);

    app(PipelineActivation::class)->markReady($first);
    app(PipelineActivation::class)->markReady($second);

    expect($first->fresh()->is_ready)->toBeTrue()
        ->and($second->fresh()->is_ready)->toBeTrue();
});

it('allows multiple active generators in a campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $first = readyPipeline(PipelineKind::Generator, $campaign);
    $second = readyPipeline(PipelineKind::Generator, $campaign);

    app(PipelineActivation::class)->markReady($first);
    app(PipelineActivation::class)->markReady($second);

    expect($campaign->pipelines()->where('is_ready', true)->count())->toBe(2);
});

it('uses persisted readiness state instead of stale loaded field relations', function (): void {
    $pipeline = readyPipeline(PipelineKind::Editor)->load('fields');
    $pipeline->fields->firstWhere('role', FieldRole::Prompt)->update(['needs_configuration' => true]);

    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))
        ->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_ready)->toBeFalse();
});

it('allows idempotent readiness of the same editor', function (): void {
    $pipeline = readyPipeline(PipelineKind::Editor);
    $activation = app(PipelineActivation::class);

    $activation->markReady($pipeline);
    $activation->markReady($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeTrue();
});

it('marks not ready under the pipeline lock and clears the default pipeline', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = readyPipeline(PipelineKind::Generator, $campaign);
    app(PipelineActivation::class)->markReady($pipeline);
    app(PipelineActivation::class)->setDefault($campaign, $pipeline);

    app(PipelineActivation::class)->markNotReady($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull();
});

it('rejects a default pipeline from another campaign or of the wrong kind', function (): void {
    $campaign = Campaign::factory()->create();
    $other = readyPipeline(PipelineKind::Generator);
    app(PipelineActivation::class)->markReady($other);
    $editor = readyPipeline(PipelineKind::Editor, $campaign);
    app(PipelineActivation::class)->markReady($editor);

    expect(fn () => app(PipelineActivation::class)->setDefault($campaign, $other))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(PipelineActivation::class)->setDefault($campaign, $editor))
        ->toThrow(ValidationException::class);
});

it('rechecks a stale generator before making it the default', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = readyPipeline(PipelineKind::Generator, $campaign);
    app(PipelineActivation::class)->markReady($pipeline);
    $stale = $pipeline->fresh();
    $stale->fresh()->update(['is_ready' => false]);

    expect(fn () => app(PipelineActivation::class)->setDefault($campaign, $stale))
        ->toThrow(ValidationException::class);
    expect($campaign->fresh()->default_pipeline_id)->toBeNull();
});

it('marks not ready, stores errors, and clears defaults in every campaign', function (): void {
    $pipeline = readyPipeline(PipelineKind::Generator);
    app(PipelineActivation::class)->markReady($pipeline);
    $first = Campaign::factory()->create();
    $second = Campaign::factory()->create();
    attachPipeline($first, $pipeline);
    attachPipeline($second, $pipeline);
    $first->update(['default_pipeline_id' => $pipeline->id]);
    $second->update(['default_pipeline_id' => $pipeline->id]);

    app(PipelineActivation::class)->markNotReady($pipeline, ['Campo x: nuevo o modificado; revisa su configuración.']);

    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($pipeline->fresh()->readiness_errors)->toBe(['Campo x: nuevo o modificado; revisa su configuración.'])
        ->and($first->fresh()->default_pipeline_id)->toBeNull()
        ->and($second->fresh()->default_pipeline_id)->toBeNull()
        ->and($first->pipelines()->count())->toBe(1);
});

it('sets a default generator only when it is a ready generator assigned to the campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $generator = attachPipeline($campaign, Pipeline::factory()->generator()->ready()->create());
    $unassigned = Pipeline::factory()->generator()->ready()->create();
    $editor = attachPipeline($campaign, Pipeline::factory()->editor()->ready()->create());
    $notReady = attachPipeline($campaign, Pipeline::factory()->generator()->create());

    app(PipelineActivation::class)->setDefault($campaign, $generator);
    expect($campaign->fresh()->default_pipeline_id)->toBe($generator->id);

    foreach ([$unassigned, $editor, $notReady] as $invalid) {
        expect(fn () => app(PipelineActivation::class)->setDefault($campaign, $invalid))
            ->toThrow(ValidationException::class);
    }
    expect($campaign->fresh()->default_pipeline_id)->toBe($generator->id);
});

it('clears defaults if marking an already ready app detects invalid persisted configuration', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $pipeline->update(['input_schema' => null]);

    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull();
});
