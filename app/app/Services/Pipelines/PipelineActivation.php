<?php

namespace App\Services\Pipelines;

use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PipelineActivation
{
    public function __construct(private readonly PipelineReadiness $readiness) {}

    public function activate(Pipeline $pipeline): void
    {
        DB::transaction(function () use ($pipeline): void {
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($pipeline->campaign_id);
            $currentPipeline = Pipeline::query()
                ->whereKey($pipeline->getKey())
                ->where('campaign_id', $campaign->id)
                ->firstOrFail();
            $errors = $this->readiness->evaluate($currentPipeline);

            if ($errors !== []) {
                throw ValidationException::withMessages(['pipeline' => $errors]);
            }

            if (in_array($currentPipeline->kind, [PipelineKind::Editor, PipelineKind::Upscaler], true)
                && Pipeline::query()
                    ->where('campaign_id', $campaign->id)
                    ->where('kind', $currentPipeline->kind)
                    ->where('is_active', true)
                    ->where('id', '!=', $currentPipeline->id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'pipeline' => ["Ya hay un {$currentPipeline->kind->value} activo en esta campaña."],
                ]);
            }

            $currentPipeline->update(['is_active' => true]);
        });
    }

    public function deactivate(Pipeline $pipeline): void
    {
        DB::transaction(function () use ($pipeline): void {
            $currentPipeline = Pipeline::query()->findOrFail($pipeline->getKey());
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($currentPipeline->campaign_id);

            $currentPipeline->update(['is_active' => false]);
            if ($campaign->default_pipeline_id === $currentPipeline->id) {
                $campaign->update(['default_pipeline_id' => null]);
            }
        });
    }

    public function setDefault(Campaign $campaign, Pipeline $pipeline): void
    {
        DB::transaction(function () use ($campaign, $pipeline): void {
            $currentCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentPipeline = Pipeline::query()->find($pipeline->getKey());

            if ($currentPipeline === null
                || $currentPipeline->campaign_id !== $currentCampaign->id
                || $currentPipeline->kind !== PipelineKind::Generator
                || ! $currentPipeline->is_active) {
                throw ValidationException::withMessages([
                    'pipeline' => ['El generador por defecto debe ser un generador activo de esta campaña.'],
                ]);
            }

            $currentCampaign->update(['default_pipeline_id' => $currentPipeline->id]);
        });
    }
}
