<?php

namespace App\Services\Pipelines;

use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignPipelineAssignment
{
    public function assign(Campaign $campaign, Pipeline $pipeline, int $sortOrder = 0): void
    {
        DB::transaction(function () use ($campaign, $pipeline, $sortOrder): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentPipeline = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());

            if (! $currentPipeline->is_ready) {
                $this->invalid('La app no está lista; revisa su configuración en el catálogo.');
            }

            if ($currentCampaign->pipelines()->whereKey($currentPipeline->id)->exists()) {
                $this->invalid('La app ya está asignada a esta campaña.');
            }

            if (in_array($currentPipeline->kind, [PipelineKind::Editor, PipelineKind::Upscaler], true)
                && $currentCampaign->pipelines()->where('pipelines.kind', $currentPipeline->kind)->exists()) {
                $this->invalid("Ya hay un {$currentPipeline->kind->value} activo en esta campaña.");
            }

            $currentCampaign->pipelines()->attach($currentPipeline->id, ['sort_order' => max(0, $sortOrder)]);
        });
    }

    public function remove(Campaign $campaign, Pipeline $pipeline): void
    {
        DB::transaction(function () use ($campaign, $pipeline): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentCampaign->pipelines()->detach($pipeline->getKey());

            if ($currentCampaign->default_pipeline_id === $pipeline->getKey()) {
                $currentCampaign->update(['default_pipeline_id' => null]);
            }
        });
    }

    public function reorder(Campaign $campaign, Pipeline $pipeline, int $sortOrder): void
    {
        DB::transaction(function () use ($campaign, $pipeline, $sortOrder): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());

            if (! $currentCampaign->pipelines()->whereKey($pipeline->getKey())->exists()) {
                $this->invalid('La app no está asignada a esta campaña.');
            }

            $currentCampaign->pipelines()->updateExistingPivot($pipeline->getKey(), ['sort_order' => max(0, $sortOrder)]);
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['pipeline' => [$message]]);
    }
}
