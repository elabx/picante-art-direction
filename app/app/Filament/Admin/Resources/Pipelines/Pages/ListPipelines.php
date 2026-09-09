<?php

namespace App\Filament\Admin\Resources\Pipelines\Pages;

use App\Engines\KreaException;
use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ListPipelines extends ListRecords
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva app')->modalHeading('Nueva app del catálogo')
                ->schema(PipelineActions::createSchema())
                ->databaseTransaction(false)
                ->before(fn () => Gate::authorize('create', Pipeline::class))
                ->after(function (Pipeline $record): void {
                    try {
                        app(PipelineSchemaSync::class)->sync($record);
                    } catch (KreaException $exception) {
                        $record->delete();
                        throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.provider_ref' => $exception->getMessage()]);
                    }
                }),
        ];
    }
}
