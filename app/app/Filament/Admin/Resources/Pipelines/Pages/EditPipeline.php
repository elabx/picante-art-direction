<?php

namespace App\Filament\Admin\Resources\Pipelines\Pages;

use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class EditPipeline extends EditRecord
{
    protected static string $resource = PipelineResource::class;

    protected ?string $subheading = 'Los campos vinculados (prompt/imagen) no pueden tener valor fijo.';

    protected function getHeaderActions(): array
    {
        return [PipelineActions::refresh(), PipelineActions::activate(), PipelineActions::deactivate()];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record;
    }

    #[On('pipeline-updated')]
    public function refreshPipeline(): void
    {
        $this->authorizeAccess();
        $this->getRecord()->refresh();
    }
}
