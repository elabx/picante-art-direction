<?php

use App\Enums\FieldRole;
use App\Enums\GenerationStatus;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Jobs\RunGenerationJob;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Generation\CreateGeneration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function configuredSourcePipeline(Campaign $campaign, PipelineKind $kind): Pipeline
{
    $properties = ['image' => ['type' => 'string']];
    if ($kind === PipelineKind::Editor) {
        $properties['prompt'] = ['type' => 'string'];
    }
    $pipeline = Pipeline::factory()->for($campaign)->create([
        'kind' => $kind, 'is_active' => true, 'readiness_errors' => [],
        'input_schema' => ['type' => 'object', 'properties' => $properties],
    ]);
    foreach ($properties as $name => $schema) {
        PipelineField::factory()->for($pipeline)->create([
            'name' => $name, 'source_schema' => $schema,
            'input_type' => $name === 'image' ? InputType::Image : InputType::String,
            'role' => $name === 'image' ? FieldRole::Image : FieldRole::Prompt,
        ]);
    }

    return $pipeline;
}

it('creates a pending series generation with an immutable typed snapshot and dispatches the run job', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);

    $generation = app(CreateGeneration::class)->series(
        $user,
        $campaign,
        $pipeline,
        ['describe_la_escena' => 'taller'],
        (string) Str::uuid(),
        3,
    );

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->execution_snapshot['inputs'])->toBe(['describe_la_escena' => 'taller'])
        ->and($generation->execution_snapshot['credential_source'])->toBe('studio')
        ->and($generation->execution_snapshot['config_revision'])->toBe(3)
        ->and($generation->execution_snapshot['labels']['describe_la_escena'])->toBe('Qué quieres ver')
        ->and($generation->execution_snapshot['schema']['describe_la_escena'])->toBe([
            'input_type' => 'string',
            'required' => true,
            'source_schema' => ['type' => 'string'],
        ])
        ->and(json_encode($generation->execution_snapshot))->not->toContain('krea_api_key');

    Queue::assertPushed(RunGenerationJob::class, fn (RunGenerationJob $job): bool => $job->generationId === $generation->id);
});

it('is idempotent only within the same authorized request context', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);
    $requestId = (string) Str::uuid();

    $first = app(CreateGeneration::class)->series($user, $campaign, $pipeline, ['describe_la_escena' => 'x'], $requestId, 3);
    $second = app(CreateGeneration::class)->series($user, $campaign, $pipeline, ['describe_la_escena' => 'x'], $requestId, 3);

    expect($first->id)->toBe($second->id);

    $outsider = User::factory()->editor()->create();
    $brand->users()->attach($outsider);
    expect(fn (): Generation => app(CreateGeneration::class)->series($outsider, $campaign, $pipeline, ['describe_la_escena' => 'x'], $requestId, 3))
        ->toThrow(AuthorizationException::class);
});

it('replays an authorized series request after its pipeline configuration changes', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);
    $requestId = (string) Str::uuid();
    $original = app(CreateGeneration::class)->series($user, $campaign, $pipeline, ['describe_la_escena' => 'x'], $requestId, 3);
    $pipeline->update(['is_active' => false, 'config_revision' => 4]);

    $replayed = app(CreateGeneration::class)->series($user, $campaign, $pipeline, ['describe_la_escena' => 'x'], $requestId, 3);

    expect($replayed->id)->toBe($original->id)
        ->and($replayed->execution_snapshot['config_revision'])->toBe(3);
});

it('rechecks a matching request id inside the transaction before validating changed pipeline configuration', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);
    $requestId = (string) Str::uuid();
    $injected = false;
    $original = null;

    DB::listen(function (QueryExecuted $query) use (&$injected, &$original, $requestId, $campaign, $pipeline, $user): void {
        if ($injected || $query->bindings !== [$requestId]) {
            return;
        }

        $injected = true;
        $original = new Generation;
        $original->forceFill([
            'campaign_id' => $campaign->id,
            'pipeline_id' => $pipeline->id,
            'user_id' => $user->id,
            'kind' => 'series',
            'request_id' => $requestId,
            'execution_snapshot' => snapshot(),
            'status' => GenerationStatus::Pending,
            'retryable' => false,
        ])->save();
        DB::table('pipelines')->where('id', $pipeline->id)->update([
            'is_active' => false,
            'config_revision' => 4,
        ]);
    });

    $replayed = app(CreateGeneration::class)->series($user, $campaign, $pipeline, ['describe_la_escena' => 'x'], $requestId, 3);

    expect($injected)->toBeTrue()
        ->and($original)->toBeInstanceOf(Generation::class)
        ->and($replayed->id)->toBe($original->id);
});

it('attaches every authorized upload reference to the generation', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'imagen_de_referencia',
        'input_type' => InputType::Image,
        'role' => FieldRole::Image,
    ]);
    $upload = InputUpload::factory()->for($brand)->for($user)->create();

    $generation = app(CreateGeneration::class)->series(
        $user,
        $campaign,
        $pipeline,
        ['describe_la_escena' => 'taller', 'imagen_de_referencia' => $upload->id],
        (string) Str::uuid(),
        3,
    );

    expect($generation->inputUploads()->pluck('input_upload_id')->all())->toBe([$upload->id])
        ->and($generation->execution_snapshot['inputs']['imagen_de_referencia'])->toBe(['__upload' => $upload->id]);
});

it('rejects stale revisions, deleted campaigns, and pipelines outside the campaign', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);

    expect(fn (): Generation => app(CreateGeneration::class)->series($user, $campaign, $pipeline, ['describe_la_escena' => 'x'], (string) Str::uuid(), 2))
        ->toThrow(ValidationException::class);

    $otherCampaign = Campaign::factory()->for($brand)->create();
    $otherPipeline = readyGenerator($otherCampaign);
    expect(fn (): Generation => app(CreateGeneration::class)->series($user, $campaign, $otherPipeline, ['describe_la_escena' => 'x'], (string) Str::uuid(), 3))
        ->toThrow(ValidationException::class);

    $campaign->delete();
    expect(fn (): Generation => app(CreateGeneration::class)->series($user, $campaign->fresh(), $pipeline, ['describe_la_escena' => 'x'], (string) Str::uuid(), 3))
        ->toThrow(ValidationException::class);
});

it('never lets the execution snapshot change after insert', function (): void {
    $generation = Generation::factory()->create();
    $generation->execution_snapshot = snapshot(['inputs' => ['x' => 1]]);

    expect(fn (): bool => $generation->save())->toThrow(LogicException::class);
});

it('selects only ready edit and upscale pipelines for a source piece', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $sourceGeneration = Generation::factory()->for($campaign)->create();
    $sourceOutput = GenerationOutput::factory()->for($sourceGeneration)->create();
    $source = Piece::factory()->for($sourceGeneration)->for($sourceOutput, 'output')->create();
    $editor = configuredSourcePipeline($campaign, PipelineKind::Editor);
    $upscaler = configuredSourcePipeline($campaign, PipelineKind::Upscaler);

    $edit = app(CreateGeneration::class)->edit($user, $source, 'ajusta la luz', (string) Str::uuid());
    $upscale = app(CreateGeneration::class)->upscale($user, $source, (string) Str::uuid());

    expect($edit->pipeline_id)->toBe($editor->id)
        ->and($upscale->pipeline_id)->toBe($upscaler->id)
        ->and($edit->parent_piece_id)->toBe($source->id)
        ->and($upscale->parent_piece_id)->toBe($source->id);
});

it('selects the active source pipeline when an older inactive pipeline exists', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $sourceGeneration = Generation::factory()->for($campaign)->create();
    $sourceOutput = GenerationOutput::factory()->for($sourceGeneration)->create();
    $source = Piece::factory()->for($sourceGeneration)->for($sourceOutput, 'output')->create();
    Pipeline::factory()->for($campaign)->create([
        'kind' => PipelineKind::Editor,
        'is_active' => false,
        'readiness_errors' => [],
        'sort_order' => 0,
    ]);
    $active = configuredSourcePipeline($campaign, PipelineKind::Editor);
    $active->update(['sort_order' => 1]);

    $generation = app(CreateGeneration::class)->edit($user, $source, 'ajusta la luz', (string) Str::uuid());

    expect($generation->pipeline_id)->toBe($active->id);
});

it('replays a source request after the active pipeline is replaced', function (): void {
    Queue::fake();
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $sourceGeneration = Generation::factory()->for($campaign)->create();
    $sourceOutput = GenerationOutput::factory()->for($sourceGeneration)->create();
    $source = Piece::factory()->for($sourceGeneration)->for($sourceOutput, 'output')->create();
    $originalPipeline = configuredSourcePipeline($campaign, PipelineKind::Editor);
    $requestId = (string) Str::uuid();
    $original = app(CreateGeneration::class)->edit($user, $source, 'ajusta la luz', $requestId);
    $originalPipeline->update(['is_active' => false]);
    Pipeline::factory()->for($campaign)->create([
        'kind' => PipelineKind::Editor,
        'is_active' => true,
        'readiness_errors' => [],
    ]);

    $replayed = app(CreateGeneration::class)->edit($user, $source, 'ajusta la luz', $requestId);

    expect($replayed->id)->toBe($original->id)
        ->and($replayed->pipeline_id)->toBe($originalPipeline->id);
});
