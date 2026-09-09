<?php

namespace App\Filament\Admin\Resources\Pipelines\Actions;

use App\Engines\KreaException;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class PipelineActions
{
    public const KINDS = ['generator' => 'Generador', 'editor' => 'Editor', 'upscaler' => 'Upscaler'];

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function createSchema(): array
    {
        return [
            Select::make('kind')->label('Tipo')->options(self::KINDS)->required(),
            TextInput::make('label')->label('Nombre')->required()->maxLength(255),
            TextInput::make('provider_ref')->label('Referencia del proveedor')->required()->maxLength(255)
                ->regex('/\A[A-Za-z0-9_.:\/-]+\z/')
                ->unique(table: Pipeline::class, column: 'provider_ref')
                ->validationMessages(['unique' => 'Esta referencia ya está en el catálogo.']),
        ];
    }

    public static function refresh(): Action
    {
        return Action::make('refresh')->label('Actualizar esquema')->authorize('update')->databaseTransaction(false)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                try {
                    app(PipelineSchemaSync::class)->sync($record->fresh());
                    Notification::make()->success()->title('Esquema actualizado.')->send();
                    $livewire->dispatch('pipeline-updated');
                } catch (KreaException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }
}
