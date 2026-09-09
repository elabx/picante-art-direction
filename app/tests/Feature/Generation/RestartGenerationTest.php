<?php

use App\Engines\Data\JobObservation;
use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Jobs\DownloadOutputJob;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\User;
use App\Services\Generation\RestartGeneration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @return array{User, Generation} */
function restartContext(array $attributes = []): array
{
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $brand->users()->attach($user);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = readyGenerator($campaign);
    $generation = Generation::factory()->for($campaign)->for($pipeline)->for($user)->create($attributes + [
        'status' => 'failed', 'failure_reason' => 'submission_unknown', 'retryable' => true,
    ]);

    return [$user, $generation];
}

it('returns one matching replacement even after the original completes and pipeline changes', function (): void {
    Queue::fake([RunGenerationJob::class]);
    [$user, $g] = restartContext(['execution_snapshot' => snapshot(['inputs' => ['x' => 1]])]);
    $request = (string) Str::uuid();

    $a = app(RestartGeneration::class)->confirmRestart($user, $g, $request);
    $g->forceFill(['status' => 'completed', 'retryable' => false])->save();
    $g->pipeline->update(['is_ready' => false, 'provider_ref' => 'changed']);
    $b = app(RestartGeneration::class)->confirmRestart($user, $g, $request);

    expect($a->id)->toBe($b->id)
        ->and($a->restarted_from_generation_id)->toBe($g->id)
        ->and($a->execution_snapshot)->toBe($g->fresh()->execution_snapshot)
        ->and($a->status)->toBe(GenerationStatus::Pending);
    Queue::assertPushed(RunGenerationJob::class, 1);
});

it('recovers all completed jobs on the original even with an inactive pipeline', function (): void {
    Queue::fake([RunGenerationJob::class, DownloadOutputJob::class]);
    $engine = fakeEngine()->setJob('j1', new JobObservation('completed', 'done', null, ['urls' => ['https://cdn.test/a.png']], null));
    [$user, $g] = restartContext(['failure_reason' => 'poll_timeout', 'submitted_at' => now()->subHour()]);
    GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);
    $g->pipeline->update(['is_ready' => false]);

    $result = app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid());

    expect($result->id)->toBe($g->id)->and($g->outputs()->count())->toBe(1)
        ->and($g->fresh()->status)->toBe(GenerationStatus::Downloading)
        ->and($engine->submissions)->toBe([]);
    Queue::assertPushed(DownloadOutputJob::class, 1);
    Queue::assertNotPushed(RunGenerationJob::class);
});

it('recovers partial results and creates a replacement for the whole original request', function (): void {
    Queue::fake([RunGenerationJob::class, DownloadOutputJob::class, PollGenerationJob::class]);
    fakeEngine()->setJob('done', new JobObservation('completed', 'done', null, ['urls' => ['https://cdn.test/a.png']], null));
    [$user, $g] = restartContext(['failure_reason' => 'poll_timeout', 'submitted_at' => now()->subHour()]);
    GenerationJob::factory()->for($g)->create(['provider_job_id' => 'done']);
    GenerationJob::factory()->for($g)->create(['provider_job_id' => 'pending']);

    $replacement = app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid());

    expect($replacement->id)->not->toBe($g->id)
        ->and($replacement->execution_snapshot)->toBe($g->fresh()->execution_snapshot)
        ->and($g->outputs()->count())->toBe(1);
    Queue::assertPushed(RunGenerationJob::class, 1);
    Queue::assertPushed(DownloadOutputJob::class, 1);
});

it('rearms accepted work with a fresh window after soft deletion and preserves active claims', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([PollGenerationJob::class, DownloadOutputJob::class, RunGenerationJob::class]);
    $engine = fakeEngine();
    [$user, $g] = restartContext(['failure_reason' => 'poll_timeout', 'submitted_at' => now()->subDay()]);
    $job = GenerationJob::factory()->for($g)->create();
    $failed = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 0, 'status' => 'failed', 'attempts' => 3]);
    $active = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 1, 'status' => 'downloading', 'attempts' => 1]);
    $stored = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 2, 'status' => 'stored', 'attempts' => 1]);
    $g->pipeline->update(['is_ready' => false]);
    $g->campaign->delete();

    app(RestartGeneration::class)->checkStatus($user, $g);

    expect($failed->fresh()->attempts)->toBe(0)->and($failed->fresh()->status)->toBe(OutputStatus::Pending)
        ->and($active->fresh()->attempts)->toBe(1)->and($active->fresh()->status)->toBe(OutputStatus::Downloading)
        ->and($stored->fresh()->status)->toBe(OutputStatus::Stored)
        ->and($g->fresh()->submitted_at->equalTo(now()->subDay()))->toBeTrue()
        ->and($engine->submissions)->toBe([]);
    Queue::assertPushed(PollGenerationJob::class, fn ($poll): bool => $poll->generationJobId === $job->id && $poll->windowStartedAt == now());
    Queue::assertPushed(DownloadOutputJob::class, 1);
    Queue::assertNotPushed(RunGenerationJob::class);
});

it('rejects stale eligibility and invalid UUIDs without dispatch', function (string $case): void {
    Queue::fake();
    [$user, $g] = restartContext();
    $request = (string) Str::uuid();
    if ($case === 'stale') {
        Generation::find($g->id)->forceFill(['status' => 'completed'])->save();
    } elseif ($case === 'not retryable') {
        Generation::find($g->id)->forceFill(['retryable' => false])->save();
    } else {
        $request = 'invalid';
    }

    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $g, $request))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
})->with(['stale', 'not retryable', 'uuid']);

it('rechecks current brand membership for recovery and accepted confirmation replay', function (): void {
    Queue::fake([RunGenerationJob::class]);
    [$user, $g] = restartContext();
    $request = (string) Str::uuid();
    app(RestartGeneration::class)->confirmRestart($user, $g, $request);
    $user->load('brands');
    $user->brands()->detach();

    expect(fn () => app(RestartGeneration::class)->checkStatus($user, $g))->toThrow(AuthorizationException::class);
    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $g, $request))->toThrow(AuthorizationException::class);
    Queue::assertPushed(RunGenerationJob::class, 1);
});

it('rejects UUID collisions from a different actor or original', function (bool $differentActor): void {
    Queue::fake([RunGenerationJob::class]);
    [$user, $g] = restartContext();
    $request = (string) Str::uuid();
    app(RestartGeneration::class)->confirmRestart($user, $g, $request);
    if ($differentActor) {
        $actor = User::factory()->editor()->create();
        $actor->brands()->attach($g->campaign->brand_id);
        $original = $g;
    } else {
        $actor = $user;
        $original = Generation::factory()->for($g->campaign)->for($g->pipeline)->for($user)->create(['status' => 'failed', 'retryable' => true]);
    }

    expect(fn () => app(RestartGeneration::class)->confirmRestart($actor, $original, $request))->toThrow(AuthorizationException::class);
    Queue::assertPushed(RunGenerationJob::class, 1);
})->with([false, true]);

it('rejects a parent piece moved outside the original campaign', function (): void {
    Queue::fake();
    $foreign = Piece::factory()->create();
    [$user, $g] = restartContext(['kind' => 'edit', 'parent_piece_id' => $foreign->id]);

    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
});

it('retains owned and fixed upload references on the replacement and pins the original snapshot', function (): void {
    Queue::fake([RunGenerationJob::class]);
    [$user, $base] = restartContext();
    $owned = InputUpload::factory()->for($base->campaign->brand)->for($user)->create();
    $fixed = InputUpload::factory()->for($base->campaign->brand)->create();
    $base->pipeline->inputUploads()->attach($fixed);
    $g = Generation::factory()->for($base->campaign)->for($base->pipeline)->for($user)->create([
        'status' => 'failed', 'retryable' => true, 'failure_reason' => 'submission_unknown',
        'execution_snapshot' => snapshot(['inputs' => ['owned' => ['__upload' => $owned->id], 'fixed' => ['__upload' => $fixed->id]], 'credential_source' => 'brand']),
    ]);
    $g->pipeline->update(['provider_ref' => 'new-ref']);

    $replacement = app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid());

    expect($replacement->inputUploads()->pluck('input_uploads.id')->sort()->values()->all())->toBe([$owned->id, $fixed->id])
        ->and($replacement->execution_snapshot)->toBe($g->fresh()->execution_snapshot);
    Queue::assertPushed(RunGenerationJob::class, 1);
});

it('rejects missing or unauthorized snapshot uploads', function (bool $missing): void {
    Queue::fake();
    [$user, $base] = restartContext();
    $upload = InputUpload::factory()->for($base->campaign->brand)->create();
    $g = Generation::factory()->for($base->campaign)->for($base->pipeline)->for($user)->create([
        'status' => 'failed', 'retryable' => true,
        'execution_snapshot' => snapshot(['inputs' => ['image' => ['__upload' => $upload->id]]]),
    ]);
    if ($missing) {
        $upload->delete();
    }

    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
})->with([false, true]);

it('blocks new executions for unavailable campaigns or pipelines', function (string $case): void {
    Queue::fake();
    [$user, $g] = restartContext();
    if ($case === 'campaign') {
        $g->campaign->delete();
    } else {
        $g->pipeline->update($case === 'inactive' ? ['is_ready' => false] : ['is_ready' => false, 'readiness_errors' => ['missing field']]);
    }

    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid()))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
})->with(['campaign', 'inactive', 'not ready']);

it('retries an existing manifest when timed out jobs were already observed completed', function (): void {
    Queue::fake([RunGenerationJob::class, DownloadOutputJob::class]);
    fakeEngine();
    [$user, $g] = restartContext(['failure_reason' => 'poll_timeout']);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $output = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['status' => 'failed', 'attempts' => 3]);

    $result = app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid());

    expect($result->id)->toBe($g->id)->and($output->fresh()->attempts)->toBe(0);
    Queue::assertPushed(DownloadOutputJob::class, 1);
    Queue::assertNotPushed(RunGenerationJob::class);
});

it('keeps the confirmed whole-request restart when inspection finds mixed terminal children', function (): void {
    Queue::fake([RunGenerationJob::class, DownloadOutputJob::class]);
    fakeEngine()->setJob('done', new JobObservation('completed', 'done', null, ['urls' => ['https://cdn.test/a.png']], null))
        ->setJob('failed', new JobObservation('failed', 'failed', null, null, null));
    [$user, $g] = restartContext(['failure_reason' => 'poll_timeout']);
    GenerationJob::factory()->for($g)->create(['provider_job_id' => 'done']);
    GenerationJob::factory()->for($g)->create(['provider_job_id' => 'failed']);

    $replacement = app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid());

    expect($replacement->restarted_from_generation_id)->toBe($g->id)->and($g->outputs()->count())->toBe(1);
    Queue::assertPushed(RunGenerationJob::class, 1);
    Queue::assertPushed(DownloadOutputJob::class, 1);
});
