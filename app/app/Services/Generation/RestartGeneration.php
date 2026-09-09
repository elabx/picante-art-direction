<?php

namespace App\Services\Generation;

use App\Engines\EngineResolver;
use App\Engines\KreaException;
use App\Enums\FailureReason;
use App\Enums\GenerationKind;
use App\Enums\GenerationStatus;
use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Media\InputUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RestartGeneration
{
    public function __construct(
        private readonly GenerationStateMachine $state,
        private readonly EngineResolver $engines,
        private readonly InputUploadService $uploads,
    ) {}

    public function checkStatus(User $user, Generation $generation): Generation
    {
        return DB::transaction(function () use ($user, $generation): Generation {
            [$fresh] = $this->context($user, $generation->id);

            return $this->state->rearm($fresh, now());
        });
    }

    public function confirmRestart(User $user, Generation $original, string $restartRequestId): Generation
    {
        if (! Str::isUuid($restartRequestId)) {
            $this->invalid('La solicitud no es válida.');
        }
        [$fresh, $campaign, $existing] = DB::transaction(function () use ($user, $original, $restartRequestId): array {
            [$fresh, $campaign, $pipeline] = $this->context($user, $original->id);
            $this->authorizeParent($fresh);
            $existing = $this->existing($user, $fresh, $restartRequestId);
            if ($existing === null) {
                $this->eligible($fresh);
                $this->authorizeUploads($user, $fresh, $campaign, $pipeline);
            }

            return [$fresh, $campaign, $existing];
        });
        if ($existing !== null) {
            return $existing;
        }

        $recoveringTimeout = $fresh->failure_reason === FailureReason::PollTimeout;
        if ($recoveringTimeout) {
            $this->inspectKnownJobs($fresh, $campaign);
        }

        try {
            return DB::transaction(function () use ($user, $original, $restartRequestId, $recoveringTimeout): Generation {
                [$fresh, $campaign, $pipeline] = $this->context($user, $original->id);
                $this->authorizeParent($fresh);
                $existing = $this->existing($user, $fresh, $restartRequestId);
                if ($existing !== null) {
                    return $existing;
                }
                $jobs = $fresh->jobs()->orderBy('id')->lockForUpdate()->get();
                if ($recoveringTimeout && $jobs->isNotEmpty() && $jobs->every(fn (GenerationJob $job): bool => $job->normalized_status === 'completed')) {
                    return $fresh;
                }
                // The confirmed timeout was eligible before inspection changed its aggregate state.
                if (! $recoveringTimeout) {
                    $this->eligible($fresh);
                }
                $uploadIds = $this->authorizeUploads($user, $fresh, $campaign, $pipeline);
                $this->ensureNewExecution($fresh, $campaign, $pipeline);
                $this->uploads->retainForReference($uploadIds);

                return $this->state->replacement($fresh, $user->id, $restartRequestId, $uploadIds);
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($user, $original, $restartRequestId, $exception): Generation {
                [$fresh] = $this->context($user, $original->id);
                $this->authorizeParent($fresh);

                return $this->existing($user, $fresh, $restartRequestId) ?? throw $exception;
            });
        }
    }

    /** @return array{Generation, Campaign, ?Pipeline} */
    private function context(User $user, int $generationId): array
    {
        $original = Generation::query()->find($generationId) ?? throw new AuthorizationException;
        $campaign = Campaign::withTrashed()->lockForUpdate()->find($original->campaign_id) ?? throw new AuthorizationException;
        if (! $user->brands()->whereKey($campaign->brand_id)->exists()) {
            throw new AuthorizationException;
        }
        $pipeline = Pipeline::query()->lockForUpdate()->find($original->pipeline_id);
        $fresh = Generation::query()->lockForUpdate()->find($generationId) ?? throw new AuthorizationException;
        if ($fresh->campaign_id !== $campaign->id || $fresh->pipeline_id !== $original->pipeline_id) {
            throw new AuthorizationException;
        }

        return [$fresh, $campaign, $pipeline];
    }

    private function existing(User $user, Generation $original, string $requestId): ?Generation
    {
        $existing = Generation::query()->where('request_id', $requestId)->first();
        if ($existing !== null && ($existing->user_id !== $user->id
            || $existing->restarted_from_generation_id !== $original->id
            || $existing->campaign_id !== $original->campaign_id
            || $existing->pipeline_id !== $original->pipeline_id
            || $existing->kind !== $original->kind
            || $existing->parent_piece_id !== $original->parent_piece_id)) {
            throw new AuthorizationException;
        }

        return $existing;
    }

    private function eligible(Generation $generation): void
    {
        if ($generation->status !== GenerationStatus::Failed || ! $generation->retryable) {
            $this->invalid('Este trabajo no admite reintento.');
        }
    }

    private function authorizeParent(Generation $generation): void
    {
        if ($generation->kind !== GenerationKind::Series
            && ! Piece::query()->whereKey($generation->parent_piece_id)->where('campaign_id', $generation->campaign_id)->exists()) {
            throw new AuthorizationException;
        }
    }

    /** @return list<int> */
    private function authorizeUploads(User $user, Generation $generation, Campaign $campaign, ?Pipeline $pipeline): array
    {
        $ids = [];
        $visit = function (mixed $value) use (&$visit, &$ids, $user, $campaign, $pipeline): void {
            if (! is_array($value)) {
                return;
            }
            if (array_key_exists('__upload', $value)) {
                $id = $value['__upload'];
                if (! is_int($id) || $id < 1) {
                    throw new AuthorizationException;
                }
                try {
                    $this->uploads->authorize($id, $campaign->brand, $user);
                } catch (AuthorizationException $exception) {
                    if ($pipeline === null || ! $campaign->pipelines()->whereKey($pipeline->id)->exists()) {
                        throw $exception;
                    }
                    $this->uploads->authorizeForPipeline($id, $pipeline);
                }
                $ids[] = $id;

                return;
            }
            foreach ($value as $nested) {
                $visit($nested);
            }
        };
        $visit($generation->execution_snapshot['inputs'] ?? []);

        return array_values(array_unique($ids));
    }

    private function inspectKnownJobs(Generation $generation, Campaign $campaign): void
    {
        foreach ($generation->jobs()->orderBy('id')->get() as $job) {
            if ($job->isTerminal()) {
                if ($job->normalized_status === 'completed') {
                    $this->state->retryOutputs($job);
                }

                continue;
            }
            try {
                $engine = $this->engines->forSource($campaign->brand, (string) ($generation->execution_snapshot['credential_source'] ?? ''));
                $observation = $engine->inspect($job->provider_job_id);
                $outputs = $observation->normalizedStatus === 'completed' ? $engine->outputs($observation->result ?? []) : [];
                $this->state->observed($job, $observation, $outputs, now(), 4);
            } catch (KreaException|ConnectionException $exception) {
                if ($exception instanceof KreaException && in_array($exception->httpStatus, [401, 404], true)) {
                    $this->state->childFailed($job, FailureReason::ProviderFailed->value, $exception->getMessage());
                }
            }
        }
    }

    private function ensureNewExecution(Generation $generation, Campaign $campaign, ?Pipeline $pipeline): void
    {
        if ($campaign->trashed()) {
            $this->invalid('Esta campaña ya no está disponible.');
        }
        [$expected, $label] = match ($generation->kind) {
            GenerationKind::Series => [PipelineKind::Generator, 'generador'],
            GenerationKind::Edit => [PipelineKind::Editor, 'editor'],
            GenerationKind::Upscale => [PipelineKind::Upscaler, 'upscaler'],
        };
        if ($pipeline === null || ! $campaign->pipelines()->whereKey($pipeline->id)->exists() || $pipeline->kind !== $expected || ! $pipeline->isReady()) {
            $this->invalid("Esta campaña no tiene {$label} configurado.");
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['generation' => $message]);
    }
}
