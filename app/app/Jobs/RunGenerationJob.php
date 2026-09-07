<?php

namespace App\Jobs;

use App\Engines\EngineResolver;
use App\Engines\KreaErrorMessages;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Services\Generation\SnapshotExpander;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RunGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $generationId) {}

    public function handle(EngineResolver $engines, SnapshotExpander $expander): void
    {
        $generation = Generation::query()->find($this->generationId);
        $campaign = $generation === null ? null : Campaign::withTrashed()->find($generation->campaign_id);
        $brand = $campaign?->brand;

        Log::withContext([
            'generation_id' => $this->generationId,
            'brand_id' => $brand?->id,
            'pipeline_id' => $generation?->pipeline_id,
        ]);

        if ($generation === null || $campaign === null || $brand === null) {
            Log::warning('Generation submission could not load its context.');

            return;
        }

        $claimed = DB::table('generations')
            ->where('id', $generation->id)
            ->where('status', GenerationStatus::Pending->value)
            ->update([
                'status' => GenerationStatus::Submitting->value,
                'submission_started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            Log::info('Generation submission claim already taken.');

            return;
        }

        Log::info('Generation submission claimed.');
        $generation->refresh();

        try {
            $inputs = $expander->expand($generation);
        } catch (RuntimeException $exception) {
            $this->fail($generation, FailureReason::ProviderFailed, $exception->getMessage());

            return;
        }

        try {
            $outcome = $engines
                ->forSource($brand, (string) ($generation->execution_snapshot['credential_source'] ?? ''))
                ->submit((string) ($generation->execution_snapshot['provider_ref'] ?? ''), $inputs);
        } catch (Throwable $exception) {
            $this->fail($generation, FailureReason::ProviderFailed, $this->safeMessage($exception->getMessage()));

            return;
        }

        if ($outcome->isAccepted()) {
            $jobIds = DB::transaction(function () use ($generation, $outcome): array {
                $now = now();
                $jobIds = [];

                foreach ($outcome->jobIds as $providerJobId) {
                    $job = new GenerationJob;
                    $job->forceFill([
                        'generation_id' => $generation->id,
                        'provider_job_id' => $providerJobId,
                        'status' => 'submitted',
                        'normalized_status' => 'pending',
                    ])->save();
                    $jobIds[] = $job->id;
                }

                $generation->forceFill([
                    'status' => GenerationStatus::Submitted,
                    'submitted_at' => $now,
                ])->save();

                return $jobIds;
            });

            Log::info('Generation submission accepted.');
            foreach ($jobIds as $jobId) {
                PollGenerationJob::dispatch($jobId)->delay(now()->addSeconds(4));
            }

            return;
        }

        if ($outcome->isUnknown()) {
            $this->fail(
                $generation,
                FailureReason::SubmissionUnknown,
                'No pudimos confirmar si el trabajo se inició.',
                true,
            );

            return;
        }

        $this->fail($generation, FailureReason::ProviderFailed, $this->safeMessage($outcome->error));
    }

    private function fail(
        Generation $generation,
        FailureReason $reason,
        ?string $message,
        bool $retryable = false,
    ): void {
        $message = $this->safeMessage($message);
        $generation->forceFill([
            'status' => GenerationStatus::Failed,
            'failure_reason' => $reason,
            'error_message' => $message,
            'retryable' => $retryable,
        ])->save();

        Log::warning('Generation submission failed: '.$message);
    }

    private function safeMessage(?string $message): string
    {
        return KreaErrorMessages::sanitizeDetail($message) ?: 'No pudimos iniciar el trabajo.';
    }
}
