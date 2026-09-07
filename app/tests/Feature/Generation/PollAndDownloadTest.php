<?php

use App\Engines\Data\JobObservation;
use App\Engines\EngineResolver;
use App\Engines\KreaException;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Enums\PieceKind;
use App\Jobs\DownloadOutputJob;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use App\Models\Piece;
use App\Services\Generation\GenerationStateMachine;
use App\Services\Media\ResultDownloader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('polls each completed child once and stores immutable pieces from its manifest', function (): void {
    Queue::fake([DownloadOutputJob::class, PollGenerationJob::class, RunGenerationJob::class]);
    Storage::fake('pieces');
    Http::preventStrayRequests();
    Http::fake(['https://cdn.test/*' => fn () => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')))]);
    $engine = fakeEngine()->setJob('j1', new JobObservation('completed', 'done', null, ['urls' => ['https://cdn.test/a.png', 'https://cdn.test/b.png']], null));
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $job = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);

    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class));
    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class));

    expect($g->fresh()->status)->toBe(GenerationStatus::Downloading)
        ->and($g->outputs()->pluck('index')->all())->toBe([0, 1]);
    Queue::assertPushed(DownloadOutputJob::class, 2);
    foreach ($g->outputs as $output) {
        (new DownloadOutputJob($output->id))->handle(app(ResultDownloader::class));
        (new DownloadOutputJob($output->id))->handle(app(ResultDownloader::class));
        expect($output->fresh()->attempts)->toBe(1);
    }
    Http::assertSentCount(2);
    expect($g->fresh()->status)->toBe(GenerationStatus::Completed)
        ->and($g->pieces()->count())->toBe(2)
        ->and($g->pieces()->first()->kind)->toBe(PieceKind::Original)
        ->and($g->fresh()->completed_at)->not->toBeNull()
        ->and($engine->submissions)->toBe([]);
    foreach ($g->pieces as $piece) {
        Storage::disk('pieces')->assertExists($piece->storage_path);
        expect($piece->storage_path)->toBe("{$g->campaign->brand_id}/{$g->campaign_id}/{$g->id}/{$piece->generation_output_id}-1.png");
    }
    Queue::assertNotPushed(RunGenerationJob::class);
});

it('backs off pending polls and propagates a manual window without altering submission time', function (int $age, int $delay): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([PollGenerationJob::class]);
    fakeEngine();
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()->subDay()]);
    $job = GenerationJob::factory()->for($g)->create();
    $start = now()->subSeconds($age);

    (new PollGenerationJob($job->id, $start))->handle(app(EngineResolver::class));

    expect($g->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($g->fresh()->submitted_at->equalTo(now()->subDay()))->toBeTrue();
    Queue::assertPushed(PollGenerationJob::class, fn ($poll): bool => $poll->generationJobId === $job->id && $poll->windowStartedAt == $start && $poll->delay->equalTo(now()->addSeconds($delay)));
})->with([[0, 4], [119, 4], [120, 8], [299, 8], [300, 15], [599, 15]]);

it('times out before inspection at the window boundary and leaves completed work intact', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([PollGenerationJob::class, DownloadOutputJob::class]);
    $engine = fakeEngine()->setJob('j1', new JobObservation('completed', 'done', null, ['urls' => ['https://cdn.test/a.png']], null));
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()->subMinutes(10)]);
    $job = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);

    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class));

    expect($g->fresh()->failure_reason)->toBe(FailureReason::PollTimeout)
        ->and($g->fresh()->retryable)->toBeTrue()
        ->and($g->outputs()->count())->toBe(0)
        ->and($engine->submissions)->toBe([]);
    $g->forceFill(['status' => GenerationStatus::Completed, 'completed_at' => now()])->save();
    app(GenerationStateMachine::class)->timeout($g);
    expect($g->fresh()->status)->toBe(GenerationStatus::Completed);
    Queue::assertNothingPushed();
});

it('fails permanent inspect errors but continues polling siblings', function (int $status): void {
    Queue::fake([PollGenerationJob::class]);
    fakeEngine()->failInspect('gone', new KreaException('Bearer very-secret https://cdn.test/a?signature=secret', $status));
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $gone = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'gone']);
    $alive = GenerationJob::factory()->for($g)->create();

    (new PollGenerationJob($gone->id))->handle(app(EngineResolver::class));
    (new PollGenerationJob($alive->id))->handle(app(EngineResolver::class));

    expect($gone->fresh()->normalized_status)->toBe('failed')
        ->and(json_encode($gone->fresh()->error))->not->toContain('very-secret', 'signature=secret')
        ->and($g->fresh()->status)->toBe(GenerationStatus::Processing);
    Queue::assertPushed(PollGenerationJob::class, fn ($poll): bool => $poll->generationJobId === $alive->id);
})->with([401, 404]);

it('retries transient inspect errors within the same window', function (bool $network): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([PollGenerationJob::class]);
    fakeEngine()->failInspect('j1', $network ? new ConnectionException('private') : new KreaException('private', 503));
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $job = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);
    $start = now();

    foreach ([15, 30, 60, 60] as $delay) {
        (new PollGenerationJob($job->id, $start))->handle(app(EngineResolver::class));
        expect($job->fresh()->next_poll_at->equalTo(now()->addSeconds($delay)))->toBeTrue();
    }
    expect($job->fresh()->poll_failures)->toBe(4);
    Queue::assertPushed(PollGenerationJob::class, 4);
})->with([false, true]);

it('settles empty and failed terminal results with durable child reasons', function (string $normalized, string $reason): void {
    Queue::fake([DownloadOutputJob::class]);
    fakeEngine()->setJob('j1', new JobObservation($normalized, $normalized, null, [], ['detail' => 'https://cdn.test/a?secret=1']));
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $job = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);

    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class));

    expect($g->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($g->fresh()->failure_reason->value)->toBe($reason)
        ->and($job->fresh()->error['failure_reason'])->toBe($reason);
    Queue::assertNothingPushed();
})->with([['completed', 'invalid_result'], ['failed', 'provider_failed'], ['cancelled', 'provider_failed']]);

it('does not settle while an output is actively downloading', function (): void {
    $g = Generation::factory()->create(['status' => 'downloading']);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    GenerationOutput::factory()->for($g)->for($job, 'job')->create(['status' => 'downloading']);

    app(GenerationStateMachine::class)->settle($g);

    expect($g->fresh()->status)->toBe(GenerationStatus::Downloading)->and($g->fresh()->completed_at)->toBeNull();
});

it('limits failed downloads to three attempts and keeps sibling assets', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([DownloadOutputJob::class]);
    Storage::fake('pieces');
    Http::preventStrayRequests();
    Http::fake(['https://cdn.test/bad.png' => Http::response('', 500), 'https://cdn.test/ok.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')))]);
    $g = Generation::factory()->create(['status' => 'downloading']);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $ok = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 0, 'source_url' => 'https://cdn.test/ok.png']);
    $bad = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 1, 'source_url' => 'https://cdn.test/bad.png']);
    (new DownloadOutputJob($ok->id))->handle(app(ResultDownloader::class));

    foreach ([5, 15] as $delay) {
        (new DownloadOutputJob($bad->id))->handle(app(ResultDownloader::class));
        expect($bad->fresh()->status)->toBe(OutputStatus::Pending)
            ->and($bad->fresh()->next_attempt_at->equalTo(now()->addSeconds($delay)))->toBeTrue();
        $this->travel($delay)->seconds();
    }
    (new DownloadOutputJob($bad->id))->handle(app(ResultDownloader::class));
    (new DownloadOutputJob($bad->id))->handle(app(ResultDownloader::class));

    expect($bad->fresh()->attempts)->toBe(3)
        ->and($bad->fresh()->failure_reason)->toBe(FailureReason::DownloadFailed)
        ->and($g->fresh()->failure_reason)->toBe(FailureReason::DownloadFailed)
        ->and($g->fresh()->retryable)->toBeTrue()
        ->and($g->pieces()->count())->toBe(1);
    Http::assertSentCount(4);
    Queue::assertPushed(DownloadOutputJob::class, 2);
});

it('preserves invalid output classification across a sibling timeout and late recovery', function (): void {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([PollGenerationJob::class, DownloadOutputJob::class]);
    Storage::fake('pieces');
    Http::preventStrayRequests();
    Http::fake(['https://cdn.test/invalid' => Http::response('not an image'), 'https://cdn.test/ok.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')))]);
    fakeEngine()->setJob('late', new JobObservation('completed', 'done', null, ['urls' => ['https://cdn.test/ok.png']], null));
    $g = Generation::factory()->create(['status' => 'downloading', 'submitted_at' => now()->subMinutes(10)]);
    $finished = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $late = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'late']);
    $invalid = GenerationOutput::factory()->for($g)->for($finished, 'job')->create(['source_url' => 'https://cdn.test/invalid']);

    (new DownloadOutputJob($invalid->id))->handle(app(ResultDownloader::class));
    (new PollGenerationJob($late->id))->handle(app(EngineResolver::class));
    expect($g->fresh()->failure_reason)->toBe(FailureReason::PollTimeout);
    (new PollGenerationJob($late->id, now()))->handle(app(EngineResolver::class));
    (new DownloadOutputJob($late->outputs()->first()->id))->handle(app(ResultDownloader::class));

    expect($invalid->fresh()->failure_reason)->toBe(FailureReason::InvalidResult)
        ->and($g->fresh()->failure_reason)->toBe(FailureReason::InvalidResult)
        ->and($g->fresh()->retryable)->toBeFalse()
        ->and($g->pieces()->count())->toBe(1);
});

it('accepts only the exact byte dimensions for upscales and retains rejected candidates', function (int $width, int $height, bool $accepted): void {
    Storage::fake('pieces');
    Http::preventStrayRequests();
    $source = Piece::factory()->create(['width' => 1920, 'height' => 1080]);
    $g = Generation::factory()->create(['kind' => 'upscale', 'parent_piece_id' => $source->id, 'status' => 'downloading', 'execution_snapshot' => snapshot(['source_piece' => ['id' => $source->id, 'width' => 1920, 'height' => 1080]])]);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $output = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/up.png']);
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    Http::fake(['https://cdn.test/up.png' => Http::response($bytes)]);

    (new DownloadOutputJob($output->id))->handle(app(ResultDownloader::class));

    $piece = $g->pieces()->sole();
    expect($piece->is_4k)->toBe($accepted)
        ->and($piece->width)->toBe($width)->and($piece->height)->toBe($height)
        ->and($piece->kind)->toBe(PieceKind::Upscale)->and($piece->root_piece_id)->toBe($source->id)
        ->and($g->fresh()->status)->toBe($accepted ? GenerationStatus::Completed : GenerationStatus::Failed)
        ->and($g->fresh()->failure_reason)->toBe($accepted ? null : FailureReason::DeliveryDimensions);
    Storage::disk('pieces')->assertExists($piece->storage_path);
})->with([[3840, 2160, true], [3840, 2159, false], [4096, 2304, false]]);

it('fences a stale downloader after recovery resets attempts and another claim stores the asset', function (): void {
    Storage::fake('pieces');
    Http::preventStrayRequests();
    $g = Generation::factory()->create(['status' => 'downloading']);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $output = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/race.png']);
    $bytes = file_get_contents(base_path('tests/Fixtures/images/tiny.png'));
    $reclaimed = false;
    Http::fake(['https://cdn.test/race.png' => function () use ($output, $bytes, &$reclaimed) {
        if (! $reclaimed) {
            $reclaimed = true;
            $output->refresh()->forceFill(['status' => 'pending', 'attempts' => 0])->save();
            (new DownloadOutputJob($output->id))->handle(app(ResultDownloader::class));

            return Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.jpg')));
        }

        return Http::response($bytes);
    }]);

    (new DownloadOutputJob($output->id))->handle(app(ResultDownloader::class));

    $piece = $g->pieces()->sole();
    expect($output->fresh()->claim_version)->toBe(2)
        ->and($output->fresh()->attempts)->toBe(1)
        ->and($piece->storage_path)->toEndWith("/{$output->id}-2.png")
        ->and(Storage::disk('pieces')->get($piece->storage_path))->toBe($bytes)
        ->and(Storage::disk('pieces')->allFiles())->toBe([$piece->storage_path]);
    Http::assertSentCount(2);
});

it('turns storage write failures into safe download retries without creating a piece', function (): void {
    Queue::fake([DownloadOutputJob::class]);
    Http::preventStrayRequests();
    Http::fake(['https://cdn.test/a.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')))]);
    Storage::shouldReceive('disk')->with('pieces')->andReturn($disk = Mockery::mock(Filesystem::class));
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('https://private.test/object?signature=secret Bearer secret'));
    $g = Generation::factory()->create(['status' => 'downloading']);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $output = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/a.png']);

    (new DownloadOutputJob($output->id))->handle(app(ResultDownloader::class));

    expect($output->fresh()->status)->toBe(OutputStatus::Pending)
        ->and($output->fresh()->error_message)->not->toContain('signature', 'secret', 'private.test')
        ->and($g->pieces()->count())->toBe(0);
    Queue::assertPushed(DownloadOutputJob::class, 1);
});
