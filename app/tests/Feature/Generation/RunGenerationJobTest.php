<?php

use App\Engines\EngineResolver;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Models\Generation;
use App\Models\Piece;
use App\Services\Generation\SnapshotExpander;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('claims, submits once, stores every accepted job id, and schedules polling', function (): void {
    Queue::fake([PollGenerationJob::class]);
    $engine = fakeEngine()->willAccept(['j1', 'j2']);
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Pending,
        'execution_snapshot' => snapshot(['inputs' => ['describe_la_escena' => 'x']]),
    ]);

    (new RunGenerationJob($generation->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));
    (new RunGenerationJob($generation->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));

    expect($engine->submissions)->toHaveCount(1)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Submitted)
        ->and($generation->jobs()->pluck('provider_job_id')->all())->toBe(['j1', 'j2']);
    Queue::assertPushed(PollGenerationJob::class, 2);
});

it('marks unknown outcomes as submission unknown and never resubmits', function (): void {
    $engine = fakeEngine()->willBeUnknown();
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Pending,
        'execution_snapshot' => snapshot(),
    ]);

    (new RunGenerationJob($generation->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->failure_reason)->toBe(FailureReason::SubmissionUnknown)
        ->and($generation->fresh()->retryable)->toBeTrue()
        ->and($generation->fresh()->error_message)->toBe('No pudimos confirmar si el trabajo se inició.');

    (new RunGenerationJob($generation->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));
    expect($engine->submissions)->toHaveCount(1);
});

it('continues accepted work after its campaign is soft deleted', function (): void {
    $engine = fakeEngine()->willAccept(['j1']);
    $generation = Generation::factory()->create(['status' => GenerationStatus::Pending]);
    $generation->campaign->delete();

    (new RunGenerationJob($generation->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));

    expect($engine->submissions)->toHaveCount(1)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Submitted);
});

it('expands upload and piece references into data urls', function (): void {
    Storage::fake('pieces');
    Storage::fake('inputs');
    $piece = Piece::factory()->create(['storage_path' => 'a/b.png', 'mime_type' => 'image/png']);
    Storage::disk('pieces')->put('a/b.png', 'PNGBYTES');
    $generation = Generation::factory()->create([
        'execution_snapshot' => snapshot(['inputs' => ['foto' => ['__piece' => $piece->id], 'txt' => 'hola']]),
    ]);

    expect(app(SnapshotExpander::class)->expand($generation))->toBe([
        'foto' => 'data:image/png;base64,'.base64_encode('PNGBYTES'),
        'txt' => 'hola',
    ]);
});

it('rejects an expanded request above the configured maximum size', function (): void {
    config()->set('media.max_request_bytes', 10);
    $generation = Generation::factory()->create([
        'execution_snapshot' => snapshot(['inputs' => ['prompt' => '1234567890']]),
    ]);

    expect(fn (): array => app(SnapshotExpander::class)->expand($generation))
        ->toThrow(RuntimeException::class, 'La solicitud supera el tamaño máximo permitido.');
});

it('sanitizes storage failures while expanding private piece references', function (): void {
    Storage::fake('pieces');
    $piece = Piece::factory()->create(['storage_path' => 'private-secret-token.png']);
    $generation = Generation::factory()->create([
        'execution_snapshot' => snapshot(['inputs' => ['foto' => ['__piece' => $piece->id]]]),
    ]);

    expect(fn (): array => app(SnapshotExpander::class)->expand($generation))
        ->toThrow(RuntimeException::class, 'No pudimos preparar los archivos solicitados.');
});
