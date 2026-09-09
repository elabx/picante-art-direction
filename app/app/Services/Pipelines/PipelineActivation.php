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

    /**
     * @throws ValidationException when the catalog entry is not ready
     */
    public function markReady(Pipeline $pipeline): void
    {
        $errors = DB::transaction(function () use ($pipeline): array {
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());
            $errors = $this->readiness->evaluate($current);
            if ($errors !== []) {
                $this->markNotReady($current, $errors);
            } else {
                $current->update(['readiness_errors' => [], 'is_ready' => true]);
            }

            return $errors;
        }, attempts: 3);

        if ($errors !== []) {
            throw ValidationException::withMessages(['pipeline' => $errors]);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    public function markNotReady(Pipeline $pipeline, array $errors = []): void
    {
        DB::transaction(function () use ($pipeline, $errors): void {
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());
            $current->update(['is_ready' => false, 'readiness_errors' => $errors]);
            Campaign::query()->withTrashed()->where('default_pipeline_id', $current->id)->update(['default_pipeline_id' => null]);
        }, attempts: 3);
    }

    public function setDefault(Campaign $campaign, Pipeline $pipeline): void
    {
        DB::transaction(function () use ($campaign, $pipeline): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentPipeline = $currentCampaign->pipelines()->whereKey($pipeline->getKey())->first();

            if ($currentPipeline === null
                || $currentPipeline->kind !== PipelineKind::Generator
                || ! $currentPipeline->is_ready) {
                throw ValidationException::withMessages([
                    'pipeline' => ['El generador por defecto debe ser un generador listo asignado a esta campaña.'],
                ]);
            }

            $currentCampaign->update(['default_pipeline_id' => $currentPipeline->id]);
        }, attempts: 3);
    }
}
