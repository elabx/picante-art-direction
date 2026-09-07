<?php

use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Jobs\DownloadOutputJob;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use Illuminate\Support\Facades\Queue;

it('marks stale submitting claims unknown and redispatches lost work without submitting', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
    $stale = Generation::factory()->create([
        'status' => 'submitting',
        'submission_started_at' => now()->subSeconds(121),
    ]);
    $fresh = Generation::factory()->create([
        'status' => 'submitting',
        'submission_started_at' => now()->subSeconds(120),
    ]);
    $stuckPending = Generation::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subSeconds(61),
    ]);
    $polling = Generation::factory()->create([
        'status' => 'processing',
        'submitted_at' => now()->subMinutes(3),
    ]);
    $lostJob = GenerationJob::factory()->for($polling)->create([
        'next_poll_at' => now()->subSeconds(121),
    ]);
    $pendingOutput = GenerationOutput::factory()->for($polling)->for($lostJob, 'job')->create([
        'status' => 'pending',
        'next_attempt_at' => now()->subSeconds(121),
    ]);
    $crashedOutput = GenerationOutput::factory()->for($polling)->for($lostJob, 'job')->create([
        'index' => 1,
        'status' => 'downloading',
        'attempts' => 2,
        'claim_version' => 2,
        'updated_at' => now()->subSeconds(301),
    ]);

    $this->artisan('media:reconcile')->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($stale->fresh()->failure_reason)->toBe(FailureReason::SubmissionUnknown)
        ->and($stale->fresh()->retryable)->toBeTrue()
        ->and($stale->fresh()->error_message)->toBe('No pudimos confirmar si el trabajo se inició.')
        ->and($fresh->fresh()->status)->toBe(GenerationStatus::Submitting)
        ->and($pendingOutput->fresh()->status)->toBe(OutputStatus::Pending)
        ->and($crashedOutput->fresh()->status)->toBe(OutputStatus::Pending)
        ->and($crashedOutput->fresh()->attempts)->toBe(2)
        ->and($crashedOutput->fresh()->claim_version)->toBe(2);
    Queue::assertPushed(RunGenerationJob::class, fn (RunGenerationJob $job): bool => $job->generationId === $stuckPending->id);
    Queue::assertPushed(PollGenerationJob::class, fn (PollGenerationJob $job): bool => $job->generationJobId === $lostJob->id
        && $job->windowStartedAt == $polling->submitted_at);
    Queue::assertPushed(DownloadOutputJob::class, fn (DownloadOutputJob $job): bool => $job->outputId === $pendingOutput->id);
    Queue::assertPushed(DownloadOutputJob::class, fn (DownloadOutputJob $job): bool => $job->outputId === $crashedOutput->id);
    Queue::assertNotPushed(RunGenerationJob::class, fn (RunGenerationJob $job): bool => $job->generationId !== $stuckPending->id);
    expect(fakeEngine()->submissions)->toBe([]);
});

it('does not revive terminal generations, stored outputs, or expired polling windows', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
    $terminal = Generation::factory()->create([
        'status' => 'failed',
        'submitted_at' => now()->subMinutes(3),
    ]);
    $terminalJob = GenerationJob::factory()->for($terminal)->create([
        'next_poll_at' => now()->subMinutes(3),
    ]);
    $terminalOutput = GenerationOutput::factory()->for($terminal)->for($terminalJob, 'job')->create([
        'status' => 'downloading',
        'updated_at' => now()->subMinutes(6),
    ]);
    $expired = Generation::factory()->create([
        'status' => 'processing',
        'submitted_at' => now()->subMinutes(10),
    ]);
    $expiredJob = GenerationJob::factory()->for($expired)->create([
        'next_poll_at' => now()->subMinutes(3),
    ]);
    $stored = GenerationOutput::factory()->for($expired)->for($expiredJob, 'job')->create([
        'status' => 'stored',
        'updated_at' => now()->subMinutes(6),
    ]);

    $this->artisan('media:reconcile')->assertExitCode(0);

    expect($terminal->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($terminalOutput->fresh()->status)->toBe(OutputStatus::Downloading)
        ->and($stored->fresh()->status)->toBe(OutputStatus::Stored);
    Queue::assertNothingPushed();
});

it('exhausts a third crashed download claim without resetting its fence', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
    $generation = Generation::factory()->create(['status' => 'downloading']);
    $job = GenerationJob::factory()->for($generation)->create(['normalized_status' => 'completed']);
    $output = GenerationOutput::factory()->for($generation)->for($job, 'job')->create([
        'status' => 'downloading',
        'attempts' => 3,
        'claim_version' => 3,
        'updated_at' => now()->subSeconds(301),
    ]);

    $this->artisan('media:reconcile')->assertExitCode(0);

    expect($output->fresh()->status)->toBe(OutputStatus::Failed)
        ->and($output->fresh()->failure_reason)->toBe(FailureReason::DownloadFailed)
        ->and($output->fresh()->attempts)->toBe(3)
        ->and($output->fresh()->claim_version)->toBe(3)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->failure_reason)->toBe(FailureReason::DownloadFailed);
    Queue::assertNothingPushed();
});
