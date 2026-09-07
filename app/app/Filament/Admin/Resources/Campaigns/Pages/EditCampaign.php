<?php

namespace App\Filament\Admin\Resources\Campaigns\Pages;

use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineActivation;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }

    protected function beforeValidate(): void
    {
        if ((string) ($this->data['brand_id'] ?? '') !== (string) $this->getRecord()->fresh()->brand_id) {
            throw ValidationException::withMessages(['data.brand_id' => 'La marca de una campaña no se puede cambiar.']);
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Campaign {
            $campaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($record->id);
            $campaign->update(Arr::only($data, ['name', 'slug', 'description', 'cover_path', 'starts_on', 'ends_on']));
            $default = $data['default_pipeline_id'] ?? null;
            if (filled($default)) {
                $pipeline = Pipeline::query()->find($default);
                if ($pipeline === null) {
                    throw ValidationException::withMessages(['data.default_pipeline_id' => 'El generador no está disponible.']);
                }
                try {
                    app(PipelineActivation::class)->setDefault($campaign, $pipeline);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['data.default_pipeline_id' => Arr::flatten($exception->errors())]);
                }
            } else {
                $campaign->update(['default_pipeline_id' => null]);
            }

            return $campaign->refresh();
        });
    }
}
