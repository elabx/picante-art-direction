<?php

namespace App\Services\Generation;

use App\Enums\FieldRole;
use App\Enums\GenerationKind;
use App\Enums\GenerationStatus;
use App\Enums\PipelineKind;
use App\Jobs\RunGenerationJob;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Media\InputUploadService;
use App\Services\Pipelines\PipelineFormBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateGeneration
{
    public function __construct(
        private readonly InputComposer $composer,
        private readonly PipelineFormBuilder $formBuilder,
        private readonly InputUploadService $uploads,
    ) {}

    /**
     * @param  array<string, mixed>  $visibleInputs
     */
    public function series(
        User $user,
        Campaign $campaign,
        Pipeline $pipeline,
        array $visibleInputs,
        string $requestId,
        int $formRevision,
    ): Generation {
        $this->assertRequestId($requestId);
        $campaign = $this->loadAuthorizedCampaign($user, $campaign->id);
        $existing = Generation::query()->where('request_id', $requestId)->first();

        if ($existing !== null) {
            return $this->existingForSeriesContext($existing, $user, $campaign, $pipeline->id);
        }

        return $this->create(
            $user,
            $campaign,
            $pipeline,
            GenerationKind::Series,
            $visibleInputs,
            $requestId,
            $formRevision,
        );
    }

    public function edit(User $user, Piece $source, string $instruction, string $requestId): Generation
    {
        return $this->fromSource($user, $source, PipelineKind::Editor, GenerationKind::Edit, $instruction, $requestId);
    }

    public function upscale(User $user, Piece $source, string $requestId): Generation
    {
        return $this->fromSource($user, $source, PipelineKind::Upscaler, GenerationKind::Upscale, null, $requestId);
    }

    private function fromSource(
        User $user,
        Piece $source,
        PipelineKind $pipelineKind,
        GenerationKind $kind,
        ?string $instruction,
        string $requestId,
    ): Generation {
        $this->assertRequestId($requestId);
        $source = Piece::query()->find($source->id) ?? throw new AuthorizationException;
        $campaign = $this->loadAuthorizedCampaign($user, $source->campaign_id);
        $existing = Generation::query()->where('request_id', $requestId)->first();

        if ($existing !== null) {
            return $this->existingForSourceContext($existing, $user, $campaign, $kind, $source);
        }

        $this->ensureCampaignAvailable($campaign);

        $pipeline = $campaign->pipelines()
            ->where('pipelines.kind', $pipelineKind)
            ->where('pipelines.is_ready', true)
            ->first();

        if ($pipeline === null || ! $pipeline->isReady()) {
            $this->invalid("Esta campaña no tiene {$this->pipelineLabel($pipelineKind)} configurado.");
        }

        return $this->create(
            $user,
            $campaign,
            $pipeline,
            $kind,
            [],
            $requestId,
            null,
            $source,
            $instruction,
        );
    }

    /**
     * @param  array<string, mixed>  $visibleInputs
     */
    private function create(
        User $user,
        Campaign $campaign,
        Pipeline $pipeline,
        GenerationKind $kind,
        array $visibleInputs,
        string $requestId,
        ?int $formRevision,
        ?Piece $source = null,
        ?string $instruction = null,
    ): Generation {
        try {
            return DB::transaction(function () use ($user, $campaign, $pipeline, $kind, $visibleInputs, $requestId, $formRevision, $source, $instruction): Generation {
                $persistedCampaign = Campaign::withTrashed()->lockForUpdate()->find($campaign->id)
                    ?? throw new AuthorizationException;
                $persistedPipeline = Pipeline::query()->lockForUpdate()->find($pipeline->id);

                $this->authorize($user, $persistedCampaign);
                $persistedSource = $this->sourceForCampaign($source, $persistedCampaign, $kind);
                $existing = Generation::query()->where('request_id', $requestId)->first();

                if ($existing !== null) {
                    if ($kind === GenerationKind::Series) {
                        return $this->existingForSeriesContext($existing, $user, $persistedCampaign, $pipeline->id);
                    }

                    return $this->existingForSourceContext($existing, $user, $persistedCampaign, $kind, $persistedSource);
                }

                $this->ensureCampaignAvailable($persistedCampaign);
                $this->ensurePipeline($persistedCampaign, $persistedPipeline, $kind);

                if ($kind === GenerationKind::Series && $formRevision !== $persistedPipeline->config_revision) {
                    $this->invalid('La configuración cambió. Recarga el formulario.');
                }

                $composed = $this->composer->compose(
                    $persistedPipeline,
                    $visibleInputs,
                    $persistedSource,
                    $instruction,
                    $user,
                    $persistedCampaign->brand,
                );
                $generation = new Generation;
                $this->uploads->retainForReference($composed['uploadIds']);
                $generation->forceFill([
                    'campaign_id' => $persistedCampaign->id,
                    'pipeline_id' => $persistedPipeline->id,
                    'user_id' => $user->id,
                    'kind' => $kind,
                    'parent_piece_id' => $persistedSource?->id,
                    'request_id' => $requestId,
                    'execution_snapshot' => $this->snapshot($persistedCampaign, $persistedPipeline, $kind, $composed['inputs'], $persistedSource),
                    'status' => GenerationStatus::Pending,
                    'retryable' => false,
                ])->save();
                $generation->inputUploads()->syncWithoutDetaching($composed['uploadIds']);

                DB::afterCommit(fn (): mixed => RunGenerationJob::dispatch($generation->id));

                return $generation;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = Generation::query()->where('request_id', $requestId)->first();

            if ($existing === null) {
                throw $exception;
            }

            $persistedCampaign = $this->loadAuthorizedCampaign($user, $campaign->id);

            if ($kind === GenerationKind::Series) {
                return $this->existingForSeriesContext($existing, $user, $persistedCampaign, $pipeline->id);
            }

            $persistedSource = $this->sourceForCampaign($source, $persistedCampaign, $kind);

            return $this->existingForSourceContext($existing, $user, $persistedCampaign, $kind, $persistedSource);
        }
    }

    private function loadAuthorizedCampaign(User $user, int $campaignId): Campaign
    {
        $persistedCampaign = Campaign::withTrashed()->find($campaignId) ?? throw new AuthorizationException;
        $this->authorize($user, $persistedCampaign);

        return $persistedCampaign;
    }

    private function authorize(User $user, Campaign $campaign): void
    {
        if (! $user->brands()->whereKey($campaign->brand_id)->exists()) {
            throw new AuthorizationException;
        }
    }

    private function ensureCampaignAvailable(Campaign $campaign): void
    {
        if ($campaign->trashed()) {
            $this->invalid('Esta campaña ya no está disponible.');
        }
    }

    private function ensurePipeline(?Campaign $campaign, ?Pipeline $pipeline, GenerationKind $kind): void
    {
        $expected = match ($kind) {
            GenerationKind::Series => PipelineKind::Generator,
            GenerationKind::Edit => PipelineKind::Editor,
            GenerationKind::Upscale => PipelineKind::Upscaler,
        };

        if ($pipeline === null
            || $campaign === null
            || $pipeline->kind !== $expected
            || ! $pipeline->isReady()
            || ! $campaign->pipelines()->whereKey($pipeline->id)->exists()) {
            $this->invalid("Esta campaña no tiene {$this->pipelineLabel($expected)} configurado.");
        }
    }

    private function sourceForCampaign(?Piece $source, Campaign $campaign, GenerationKind $kind): ?Piece
    {
        if ($kind === GenerationKind::Series) {
            return null;
        }

        $persistedSource = $source === null ? null : Piece::query()->find($source->id);

        if ($persistedSource === null || $persistedSource->campaign_id !== $campaign->id) {
            throw new AuthorizationException;
        }

        return $persistedSource;
    }

    private function existingForSeriesContext(
        Generation $generation,
        User $user,
        Campaign $campaign,
        int $pipelineId,
    ): Generation {
        if ($generation->user_id !== $user->id
            || $generation->campaign_id !== $campaign->id
            || $generation->pipeline_id !== $pipelineId
            || $generation->kind !== GenerationKind::Series
            || $generation->parent_piece_id !== null) {
            throw new AuthorizationException;
        }

        return $generation;
    }

    private function existingForSourceContext(
        Generation $generation,
        User $user,
        Campaign $campaign,
        GenerationKind $kind,
        Piece $source,
    ): Generation {
        if ($generation->user_id !== $user->id
            || $generation->campaign_id !== $campaign->id
            || $generation->kind !== $kind
            || $generation->parent_piece_id !== $source->id) {
            throw new AuthorizationException;
        }

        return $generation;
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function snapshot(
        Campaign $campaign,
        Pipeline $pipeline,
        GenerationKind $kind,
        array $inputs,
        ?Piece $source,
    ): array {
        $fields = $pipeline->fields()
            ->where('stale', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $labels = [];
        $schema = [];
        foreach ($fields as $field) {
            $labels[$field->name] = $this->fieldLabel($field);
            $schema[$field->name] = [
                'input_type' => $field->input_type->value,
                'required' => $field->required,
                'source_schema' => $field->source_schema ?? [],
            ];
        }

        return [
            'engine' => 'krea',
            'provider_ref' => $pipeline->provider_ref,
            'pipeline_label' => $pipeline->label,
            'config_revision' => $pipeline->config_revision,
            'credential_source' => $campaign->brand->resolveKreaKeySource(),
            'kind' => $kind->value,
            'inputs' => $inputs,
            'labels' => $labels,
            'bindings' => [
                'image' => $fields->firstWhere('role', FieldRole::Image)?->name,
                'prompt' => $fields->firstWhere('role', FieldRole::Prompt)?->name,
            ],
            'schema' => $schema,
            'source_piece' => $source === null ? null : [
                'id' => $source->id,
                'width' => $source->width,
                'height' => $source->height,
            ],
        ];
    }

    private function fieldLabel(PipelineField $field): string
    {
        return $this->formBuilder->label($field);
    }

    private function pipelineLabel(PipelineKind $kind): string
    {
        return match ($kind) {
            PipelineKind::Generator => 'generador',
            PipelineKind::Editor => 'editor',
            PipelineKind::Upscaler => 'upscaler',
        };
    }

    private function assertRequestId(string $requestId): void
    {
        if (! Str::isUuid($requestId)) {
            $this->invalid('La solicitud no es válida.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['generation' => $message]);
    }
}
