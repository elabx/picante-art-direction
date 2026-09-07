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
    $pipeline = Pipeline::factory()->for($campaign ?? Campaign::factory())->create([
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

    return $pipeline;
}

it('refuses to activate a pipeline that is not ready', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => null]);

    expect(fn () => app(PipelineActivation::class)->activate($pipeline))
        ->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_active)->toBeFalse();
});

it('allows only one active editor per campaign and takes a campaign row lock', function (): void {
    $sqls = [];
    DB::listen(function ($query) use (&$sqls): void {
        $sqls[] = $query->sql;
    });
    $campaign = Campaign::factory()->create();
    $first = readyPipeline(PipelineKind::Editor, $campaign);
    $second = readyPipeline(PipelineKind::Editor, $campaign);

    app(PipelineActivation::class)->activate($first);

    expect(fn () => app(PipelineActivation::class)->activate($second))
        ->toThrow(ValidationException::class)
        ->and($campaign->pipelines()->where('is_active', true)->count())->toBe(1)
        ->and(collect($sqls)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'for update')))->toBeTrue();
});

it('allows one active editor in each campaign', function (): void {
    $first = readyPipeline(PipelineKind::Editor);
    $second = readyPipeline(PipelineKind::Editor);

    app(PipelineActivation::class)->activate($first);
    app(PipelineActivation::class)->activate($second);

    expect($first->fresh()->is_active)->toBeTrue()
        ->and($second->fresh()->is_active)->toBeTrue();
});

it('allows multiple active generators in a campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $first = readyPipeline(PipelineKind::Generator, $campaign);
    $second = readyPipeline(PipelineKind::Generator, $campaign);

    app(PipelineActivation::class)->activate($first);
    app(PipelineActivation::class)->activate($second);

    expect($campaign->pipelines()->where('is_active', true)->count())->toBe(2);
});

it('uses persisted readiness state instead of stale loaded field relations', function (): void {
    $pipeline = readyPipeline(PipelineKind::Editor)->load('fields');
    $pipeline->fields->firstWhere('role', FieldRole::Prompt)->update(['needs_configuration' => true]);

    expect(fn () => app(PipelineActivation::class)->activate($pipeline))
        ->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_active)->toBeFalse();
});

it('allows idempotent activation of the same editor', function (): void {
    $pipeline = readyPipeline(PipelineKind::Editor);
    $activation = app(PipelineActivation::class);

    $activation->activate($pipeline);
    $activation->activate($pipeline);

    expect($pipeline->fresh()->is_active)->toBeTrue();
});

it('deactivates under the campaign lock and clears the default pipeline', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = readyPipeline(PipelineKind::Generator, $campaign);
    app(PipelineActivation::class)->activate($pipeline);
    app(PipelineActivation::class)->setDefault($campaign, $pipeline);

    app(PipelineActivation::class)->deactivate($pipeline);

    expect($pipeline->fresh()->is_active)->toBeFalse()
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull();
});

it('rejects a default pipeline from another campaign or of the wrong kind', function (): void {
    $campaign = Campaign::factory()->create();
    $other = readyPipeline(PipelineKind::Generator);
    app(PipelineActivation::class)->activate($other);
    $editor = readyPipeline(PipelineKind::Editor, $campaign);
    app(PipelineActivation::class)->activate($editor);

    expect(fn () => app(PipelineActivation::class)->setDefault($campaign, $other))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(PipelineActivation::class)->setDefault($campaign, $editor))
        ->toThrow(ValidationException::class);
});

it('rechecks a stale generator before making it the default', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = readyPipeline(PipelineKind::Generator, $campaign);
    app(PipelineActivation::class)->activate($pipeline);
    $stale = $pipeline->fresh();
    $stale->fresh()->update(['is_active' => false]);

    expect(fn () => app(PipelineActivation::class)->setDefault($campaign, $stale))
        ->toThrow(ValidationException::class);
    expect($campaign->fresh()->default_pipeline_id)->toBeNull();
});
