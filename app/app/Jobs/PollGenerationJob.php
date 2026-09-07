<?php

namespace App\Jobs;

use App\Engines\EngineResolver;
use App\Engines\KreaException;
use App\Enums\FailureReason;
use App\Models\Campaign;
use App\Models\GenerationJob;
use App\Services\Generation\GenerationStateMachine;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

final class PollGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $generationJobId, public ?DateTimeInterface $windowStartedAt = null) {}

    public function handle(EngineResolver $engines): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        if ($job === null || $job->isTerminal()) {
            return;
        }
        $generation = $job->generation;
        $state = app(GenerationStateMachine::class);
        $start = $this->windowStartedAt ?? $generation->submitted_at ?? $generation->created_at;
        $elapsed = Carbon::instance($start)->diffInSeconds(now());
        if ($elapsed >= 600) {
            $state->timeout($generation, $job->id);

            return;
        }
        $campaign = Campaign::withTrashed()->find($generation->campaign_id);
        if ($campaign === null) {
            return;
        }
        try {
            $engine = $engines->forSource($campaign->brand, (string) ($generation->execution_snapshot['credential_source'] ?? ''));
            $observation = $engine->inspect($job->provider_job_id);
            $outputs = $observation->normalizedStatus === 'completed' ? $engine->outputs($observation->result ?? []) : [];
        } catch (KreaException|ConnectionException $exception) {
            if ($exception instanceof KreaException && in_array($exception->httpStatus, [401, 404], true)) {
                $state->childFailed($job, FailureReason::ProviderFailed->value, $exception->getMessage());
            } else {
                $state->pollFailed($job, $start);
            }

            return;
        }
        $state->observed($job, $observation, $outputs, $start, $elapsed < 120 ? 4 : ($elapsed < 300 ? 8 : 15));
    }
}
