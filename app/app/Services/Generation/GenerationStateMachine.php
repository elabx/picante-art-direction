<?php

namespace App\Services\Generation;

use App\Engines\Data\JobObservation;
use App\Engines\Data\OutputRef;
use App\Engines\KreaErrorMessages;
use App\Enums\FailureReason;
use App\Enums\GenerationKind;
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
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class GenerationStateMachine
{
    /** All mutations acquire the generation before its children and outputs. */
    private function locked(int $generationId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($generationId, $callback): mixed {
            $generation = Generation::query()->lockForUpdate()->find($generationId);

            return $generation === null ? null : $callback($generation);
        });
    }

    /** @param list<int> $uploadIds */
    public function replacement(Generation $original, int $userId, string $requestId, array $uploadIds): Generation
    {
        return $this->locked($original->id, function (Generation $fresh) use ($userId, $requestId, $uploadIds): Generation {
            $replacement = new Generation;
            $replacement->forceFill([
                'campaign_id' => $fresh->campaign_id, 'pipeline_id' => $fresh->pipeline_id,
                'user_id' => $userId, 'kind' => $fresh->kind, 'parent_piece_id' => $fresh->parent_piece_id,
                'execution_snapshot' => $fresh->execution_snapshot, 'restarted_from_generation_id' => $fresh->id,
                'request_id' => $requestId, 'status' => GenerationStatus::Pending, 'retryable' => false,
            ])->save();
            $replacement->inputUploads()->syncWithoutDetaching($uploadIds);
            DB::afterCommit(fn () => RunGenerationJob::dispatch($replacement->id));

            return $replacement;
        });
    }

    public function settle(Generation $generation): void
    {
        $this->locked($generation->id, fn (Generation $fresh) => $this->settleLocked($fresh));
    }

    public function reconcileStaleSubmission(int $generationId, DateTimeInterface $cutoff): bool
    {
        return $this->locked($generationId, function (Generation $generation) use ($cutoff): bool {
            if ($generation->status !== GenerationStatus::Submitting
                || $generation->submission_started_at === null
                || ! $generation->submission_started_at->lessThan($cutoff)) {
                return false;
            }

            $this->failed($generation, FailureReason::SubmissionUnknown, 'No pudimos confirmar si el trabajo se inició.', true);

            return true;
        }) ?? false;
    }

    public function redispatchStalePending(int $generationId, DateTimeInterface $cutoff): bool
    {
        return $this->locked($generationId, function (Generation $generation) use ($cutoff): bool {
            if ($generation->status !== GenerationStatus::Pending || ! $generation->created_at->lessThan($cutoff)) {
                return false;
            }

            DB::afterCommit(fn () => RunGenerationJob::dispatch($generation->id));

            return true;
        }) ?? false;
    }

    public function redispatchStalePoll(int $generationJobId, DateTimeInterface $cutoff): bool
    {
        $job = GenerationJob::query()->find($generationJobId);
        if ($job === null) {
            return false;
        }

        return $this->locked($job->generation_id, function (Generation $generation) use ($generationJobId, $cutoff): bool {
            $job = $generation->jobs()->lockForUpdate()->find($generationJobId);
            $withinAutomaticWindow = $generation->submitted_at !== null
                && $generation->submitted_at->greaterThan(now()->subSeconds(600));
            $overdue = $job?->next_poll_at !== null && $job->next_poll_at->lessThan($cutoff);
            $missing = $job?->next_poll_at === null
                && in_array($generation->status, [GenerationStatus::Submitted, GenerationStatus::Processing], true)
                && $generation->submitted_at !== null
                && $generation->submitted_at->lessThan($cutoff);
            if ($generation->status->isTerminal() || $job === null || $job->isTerminal() || ! $withinAutomaticWindow || ! ($overdue || $missing)) {
                return false;
            }

            DB::afterCommit(fn () => PollGenerationJob::dispatch($job->id, $generation->submitted_at));

            return true;
        }) ?? false;
    }

    public function reconcileStaleOutput(int $outputId, DateTimeInterface $pendingCutoff, DateTimeInterface $downloadingCutoff): ?OutputStatus
    {
        $output = GenerationOutput::query()->find($outputId);
        if ($output === null) {
            return null;
        }

        return $this->locked($output->generation_id, function (Generation $generation) use ($outputId, $pendingCutoff, $downloadingCutoff): ?OutputStatus {
            $output = $generation->outputs()->lockForUpdate()->find($outputId);
            $pending = $output?->status === OutputStatus::Pending
                && $output->next_attempt_at !== null
                && $output->next_attempt_at->lessThan($pendingCutoff);
            $downloading = $output?->status === OutputStatus::Downloading
                && $output->updated_at->lessThan($downloadingCutoff);
            if ($generation->status->isTerminal() || $output === null || ! ($pending || $downloading)) {
                return null;
            }

            if ($output->attempts >= 3) {
                $output->forceFill([
                    'status' => OutputStatus::Failed,
                    'failure_reason' => FailureReason::DownloadFailed,
                    'error_message' => 'No se pudo descargar el resultado.',
                    'next_attempt_at' => null,
                ])->save();
                $this->settleLocked($generation);

                return OutputStatus::Failed;
            }

            $output->forceFill([
                'status' => OutputStatus::Pending,
                'next_attempt_at' => null,
            ])->save();
            DB::afterCommit(fn () => DownloadOutputJob::dispatch($output->id));

            return OutputStatus::Pending;
        });
    }

    private function settleLocked(Generation $generation): void
    {
        $jobs = $generation->jobs()->orderBy('id')->lockForUpdate()->get();
        $outputs = $generation->outputs()->orderBy('id')->lockForUpdate()->get();
        if ($jobs->isEmpty() || $jobs->contains(fn (GenerationJob $job): bool => ! $job->isTerminal())
            || $outputs->contains(fn (GenerationOutput $output): bool => in_array($output->status, [OutputStatus::Pending, OutputStatus::Downloading], true))) {
            return;
        }

        $failedOutputs = $outputs->where('status', OutputStatus::Failed);
        $failedJobs = $jobs->filter(fn (GenerationJob $job): bool => $job->normalized_status !== 'completed');
        $stored = $outputs->where('status', OutputStatus::Stored);
        if ($stored->isNotEmpty() && $failedOutputs->isEmpty() && $failedJobs->isEmpty()) {
            if ($generation->kind === GenerationKind::Upscale && $generation->pieces()->where('is_4k', false)->exists()) {
                $this->failed($generation, FailureReason::DeliveryDimensions, 'La imagen recibida no cumple las dimensiones de entrega en 4K.');

                return;
            }
            $generation->forceFill([
                'status' => GenerationStatus::Completed, 'failure_reason' => null,
                'error_message' => null, 'retryable' => false, 'completed_at' => $generation->completed_at ?? now(),
            ])->save();

            return;
        }

        if ($failedOutputs->contains('failure_reason', FailureReason::InvalidResult)) {
            $this->failed($generation, FailureReason::InvalidResult, 'El resultado recibido no es una imagen válida.');
        } elseif ($failedOutputs->isNotEmpty()) {
            $this->failed($generation, FailureReason::DownloadFailed, 'No se pudo descargar el resultado.', true);
        } else {
            $invalid = $failedJobs->first(fn (GenerationJob $job): bool => ($job->error['failure_reason'] ?? null) === FailureReason::InvalidResult->value);
            $job = $invalid ?? $failedJobs->first();
            $reason = $invalid !== null || $job === null ? FailureReason::InvalidResult : FailureReason::ProviderFailed;
            $message = $job?->error['message'] ?? ($reason === FailureReason::InvalidResult
                ? 'El resultado recibido no contiene imágenes.'
                : 'El proceso terminó con estado "'.KreaErrorMessages::sanitizeDetail($job->status).'".');
            $this->failed($generation, $reason, $message);
        }
    }

    public function timeout(Generation $generation, ?int $jobId = null): void
    {
        $this->locked($generation->id, function (Generation $fresh) use ($jobId): void {
            if ($fresh->status === GenerationStatus::Completed) {
                return;
            }
            $jobs = $fresh->jobs()->orderBy('id')->lockForUpdate()->get();
            if ($jobId !== null && ($jobs->firstWhere('id', $jobId)?->isTerminal() ?? true)) {
                return;
            }
            if (! $jobs->contains(fn (GenerationJob $job): bool => ! $job->isTerminal())) {
                return;
            }
            $this->failed($fresh, FailureReason::PollTimeout, 'Se agotó el tiempo de espera. El trabajo podría seguir en curso.', true);
        });
    }

    public function childFailed(GenerationJob $job, string $reason, ?string $message = null): void
    {
        $this->locked($job->generation_id, function (Generation $generation) use ($job, $reason, $message): void {
            $fresh = $generation->jobs()->lockForUpdate()->find($job->id);
            if ($fresh === null || $fresh->isTerminal()) {
                return;
            }
            $fresh->forceFill([
                'status' => 'failed', 'normalized_status' => 'failed', 'last_polled_at' => now(), 'next_poll_at' => null,
                'error' => ['failure_reason' => $reason, 'message' => KreaErrorMessages::sanitizeDetail($message) ?: 'El proceso terminó con estado "failed".'],
            ])->save();
            $this->settleLocked($generation);
        });
    }

    /** @param list<OutputRef> $outputs */
    public function observed(GenerationJob $job, JobObservation $observation, array $outputs, DateTimeInterface $windowStartedAt, int $pendingDelay): void
    {
        $this->locked($job->generation_id, function (Generation $generation) use ($job, $observation, $outputs, $windowStartedAt, $pendingDelay): void {
            $fresh = $generation->jobs()->lockForUpdate()->find($job->id);
            if ($fresh === null || $fresh->isTerminal()) {
                return;
            }
            $error = $observation->error === null ? null : ['provider' => KreaErrorMessages::sanitizeDetail(json_encode($observation->error))];
            $normalized = $observation->normalizedStatus;
            if ($normalized === 'completed' && $outputs === []) {
                $normalized = 'failed';
                $error = ($error ?? []) + ['failure_reason' => FailureReason::InvalidResult->value, 'message' => 'El resultado recibido no contiene imágenes.'];
            } elseif (in_array($normalized, ['failed', 'cancelled'], true)) {
                $error = ($error ?? []) + ['failure_reason' => FailureReason::ProviderFailed->value];
            }
            $fresh->forceFill([
                'status' => mb_strcut(KreaErrorMessages::sanitizeDetail($observation->nativeStatus) ?? '', 0, 40),
                'normalized_status' => $normalized, 'queue_position' => $observation->queuePosition,
                'result' => $observation->result, 'error' => $error, 'last_polled_at' => now(),
                'next_poll_at' => $observation->isTerminal() ? null : now()->addSeconds($pendingDelay),
            ])->save();
            if ($generation->status === GenerationStatus::Submitted) {
                $generation->forceFill(['status' => GenerationStatus::Processing])->save();
            }
            if ($normalized === 'completed') {
                foreach ($outputs as $index => $ref) {
                    $output = $fresh->outputs()->where('index', $index)->first();
                    if ($output === null) {
                        $output = new GenerationOutput;
                        $output->forceFill([
                            'generation_id' => $generation->id, 'generation_job_id' => $fresh->id,
                            'index' => $index, 'source_url' => $ref->url, 'status' => OutputStatus::Pending,
                        ])->save();
                    }
                    if ($output->status === OutputStatus::Pending) {
                        DB::afterCommit(fn () => DownloadOutputJob::dispatch($output->id));
                    }
                }
                $this->inProgress($generation, GenerationStatus::Downloading);
            } elseif (! $fresh->isTerminal()) {
                DB::afterCommit(fn () => PollGenerationJob::dispatch($fresh->id, $windowStartedAt)->delay(now()->addSeconds($pendingDelay)));
            }
            $this->settleLocked($generation);
        });
    }

    public function pollFailed(GenerationJob $job, DateTimeInterface $windowStartedAt): void
    {
        $this->locked($job->generation_id, function (Generation $generation) use ($job, $windowStartedAt): void {
            $fresh = $generation->jobs()->lockForUpdate()->find($job->id);
            if ($fresh === null || $fresh->isTerminal()) {
                return;
            }
            $failures = $fresh->poll_failures + 1;
            $delay = [15, 30, 60][min($failures - 1, 2)];
            $fresh->forceFill(['poll_failures' => $failures, 'last_polled_at' => now(), 'next_poll_at' => now()->addSeconds($delay)])->save();
            DB::afterCommit(fn () => PollGenerationJob::dispatch($fresh->id, $windowStartedAt)->delay(now()->addSeconds($delay)));
        });
    }

    public function claimOutput(int $outputId): ?GenerationOutput
    {
        $output = GenerationOutput::query()->find($outputId);
        if ($output === null) {
            return null;
        }

        return $this->locked($output->generation_id, function (Generation $generation) use ($outputId): ?GenerationOutput {
            $output = $generation->outputs()->lockForUpdate()->find($outputId);
            if ($output === null || ! in_array($output->status, [OutputStatus::Pending, OutputStatus::Failed], true)
                || $output->attempts >= 3 || ($output->status === OutputStatus::Failed && $output->failure_reason === FailureReason::InvalidResult)
                || $output->next_attempt_at?->isFuture()) {
                return null;
            }
            $output->forceFill([
                'status' => OutputStatus::Downloading, 'attempts' => $output->attempts + 1,
                'claim_version' => $output->claim_version + 1, 'next_attempt_at' => null,
            ])->save();
            $this->inProgress($generation, GenerationStatus::Downloading);

            return $output;
        });
    }

    public function outputInvalid(GenerationOutput $claim): void
    {
        $this->outputFailed($claim, FailureReason::InvalidResult);
    }

    public function outputFailed(GenerationOutput $claim, FailureReason $reason = FailureReason::DownloadFailed): void
    {
        $this->locked($claim->generation_id, function (Generation $generation) use ($claim, $reason): void {
            $output = $generation->outputs()->lockForUpdate()->find($claim->id);
            if (! $this->ownsClaim($output, $claim)) {
                return;
            }
            $retry = $reason === FailureReason::DownloadFailed && $output->attempts < 3;
            $delay = $retry ? [5, 15][$output->attempts - 1] : null;
            $output->forceFill([
                'status' => $retry ? OutputStatus::Pending : OutputStatus::Failed, 'failure_reason' => $reason,
                'error_message' => $reason === FailureReason::InvalidResult ? 'El resultado recibido no es una imagen válida.' : 'No se pudo descargar el resultado.',
                'next_attempt_at' => $retry ? now()->addSeconds($delay) : null,
            ])->save();
            if ($retry) {
                DB::afterCommit(fn () => DownloadOutputJob::dispatch($output->id)->delay(now()->addSeconds($delay)));
            }
            $this->settleLocked($generation);
        });
    }

    /** @param array{bytes: string, mime: string, width: int, height: int, ext: string} $image */
    public function outputStored(GenerationOutput $claim, array $image, string $path): bool
    {
        return $this->locked($claim->generation_id, function (Generation $generation) use ($claim, $image, $path): bool {
            $output = $generation->outputs()->lockForUpdate()->find($claim->id);
            if (! $this->ownsClaim($output, $claim)) {
                return false;
            }
            $parent = $generation->parentPiece;
            $source = $generation->execution_snapshot['source_piece'] ?? [];
            $is4k = $generation->kind === GenerationKind::Upscale
                && ($source['width'] ?? 0) > 0 && ($source['height'] ?? 0) > 0
                && FourKRule::accepts($source['width'], $source['height'], $image['width'], $image['height']);
            $piece = Piece::query()->where('generation_output_id', $output->id)->first();
            if ($piece === null) {
                $piece = new Piece;
                $piece->forceFill([
                    'generation_id' => $generation->id, 'generation_output_id' => $output->id,
                    'campaign_id' => $generation->campaign_id,
                    'kind' => match ($generation->kind) {
                        GenerationKind::Series => PieceKind::Original,
                        GenerationKind::Edit => PieceKind::Edit,
                        GenerationKind::Upscale => PieceKind::Upscale,
                    },
                    'parent_piece_id' => $generation->parent_piece_id, 'root_piece_id' => $parent?->rootId(),
                    'storage_path' => $path, 'source_url' => $output->source_url,
                    'width' => $image['width'], 'height' => $image['height'], 'bytes' => strlen($image['bytes']),
                    'mime_type' => $image['mime'], 'index' => $output->index, 'is_4k' => $is4k,
                ])->save();
            }
            $output->forceFill(['status' => OutputStatus::Stored, 'failure_reason' => null, 'error_message' => null, 'next_attempt_at' => null])->save();
            $this->settleLocked($generation);

            return $piece->storage_path === $path;
        }) ?? false;
    }

    private function ownsClaim(?GenerationOutput $output, GenerationOutput $claim): bool
    {
        return $output !== null && $output->status === OutputStatus::Downloading && $output->claim_version === $claim->claim_version;
    }

    public function rearm(Generation $generation, DateTimeInterface $windowStartedAt): Generation
    {
        return $this->locked($generation->id, function (Generation $fresh) use ($windowStartedAt): Generation {
            $jobs = $fresh->jobs()->orderBy('id')->lockForUpdate()->get();
            $outputs = $fresh->outputs()->orderBy('id')->lockForUpdate()->get();
            foreach ($jobs as $job) {
                if (! $job->isTerminal()) {
                    $job->forceFill(['next_poll_at' => now()])->save();
                    DB::afterCommit(fn () => PollGenerationJob::dispatch($job->id, $windowStartedAt));
                }
            }
            foreach ($outputs as $output) {
                $this->rearmOutputLocked($output);
            }

            return $fresh;
        });
    }

    public function retryOutputs(GenerationJob $job): void
    {
        $this->locked($job->generation_id, function (Generation $generation) use ($job): void {
            foreach ($generation->outputs()->where('generation_job_id', $job->id)->orderBy('id')->lockForUpdate()->get() as $output) {
                $this->rearmOutputLocked($output);
            }
        });
    }

    private function rearmOutputLocked(GenerationOutput $output): void
    {
        if (in_array($output->status, [OutputStatus::Pending, OutputStatus::Failed], true)) {
            $output->forceFill(['status' => OutputStatus::Pending, 'attempts' => 0, 'next_attempt_at' => null])->save();
            DB::afterCommit(fn () => DownloadOutputJob::dispatch($output->id));
        }
    }

    private function inProgress(Generation $generation, GenerationStatus $status): void
    {
        if ($generation->status === GenerationStatus::Completed) {
            return;
        }
        $generation->forceFill(['status' => $status, 'failure_reason' => null, 'error_message' => null, 'retryable' => false, 'completed_at' => null])->save();
    }

    private function failed(Generation $generation, FailureReason $reason, string $message, bool $retryable = false): void
    {
        $generation->forceFill([
            'status' => GenerationStatus::Failed, 'failure_reason' => $reason,
            'error_message' => KreaErrorMessages::sanitizeDetail($message), 'retryable' => $retryable, 'completed_at' => now(),
        ])->save();
    }
}
