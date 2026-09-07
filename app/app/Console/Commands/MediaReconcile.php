<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Enums\OutputStatus;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use App\Services\Generation\GenerationStateMachine;
use Illuminate\Console\Command;

final class MediaReconcile extends Command
{
    protected $signature = 'media:reconcile';

    protected $description = 'Re-dispatch stale local media work without submitting new provider jobs';

    public function handle(GenerationStateMachine $state): int
    {
        $now = now();
        $submissionCutoff = $now->copy()->subSeconds(120);
        $pendingCutoff = $now->copy()->subSeconds(60);
        $pollCutoff = $now->copy()->subSeconds(120);
        $downloadCutoff = $now->copy()->subSeconds(300);

        foreach (Generation::query()
            ->where('status', GenerationStatus::Submitting)
            ->where('submission_started_at', '<', $submissionCutoff)
            ->pluck('id') as $generationId) {
            if ($state->reconcileStaleSubmission($generationId, $submissionCutoff)) {
                $this->line("generation:{$generationId} submitting->failed");
            }
        }

        foreach (Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<', $pendingCutoff)
            ->pluck('id') as $generationId) {
            if ($state->redispatchStalePending($generationId, $pendingCutoff)) {
                $this->line("generation:{$generationId} pending redispatched");
            }
        }

        foreach (GenerationJob::query()
            ->whereNotIn('normalized_status', ['completed', 'failed', 'cancelled'])
            ->where(function ($query) use ($pollCutoff): void {
                $query->where('next_poll_at', '<', $pollCutoff)->orWhereNull('next_poll_at');
            })
            ->pluck('id') as $generationJobId) {
            if ($state->redispatchStalePoll($generationJobId, $pollCutoff)) {
                $this->line("generation-job:{$generationJobId} poll redispatched");
            }
        }

        foreach (GenerationOutput::query()
            ->where(function ($query) use ($pendingCutoff, $downloadCutoff): void {
                $query->where(function ($query) use ($pendingCutoff): void {
                    $query->where('status', OutputStatus::Pending)->where('next_attempt_at', '<', $pendingCutoff);
                })->orWhere(function ($query) use ($downloadCutoff): void {
                    $query->where('status', OutputStatus::Downloading)->where('updated_at', '<', $downloadCutoff);
                });
            })
            ->pluck('id') as $outputId) {
            $status = $state->reconcileStaleOutput($outputId, $pendingCutoff, $downloadCutoff);
            if ($status === OutputStatus::Pending) {
                $this->line("generation-output:{$outputId} pending redispatched");
            } elseif ($status === OutputStatus::Failed) {
                $this->line("generation-output:{$outputId} downloading->failed");
            }
        }

        return self::SUCCESS;
    }
}
