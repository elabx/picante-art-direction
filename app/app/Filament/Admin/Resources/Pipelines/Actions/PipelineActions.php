<?php

namespace App\Filament\Admin\Resources\Pipelines\Actions;

use App\Engines\KreaException;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineActivation;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        return Action::make('refresh')->label('Refrescar esquema')->authorize('update')->databaseTransaction(false)
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

    public static function markReady(): Action
    {
        return Action::make('markReady')->label('Marcar lista')->authorize('update')
            ->visible(fn (Pipeline $record): bool => ! $record->is_ready)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                try {
                    app(PipelineActivation::class)->markReady($record);
                    Notification::make()->success()->title('App marcada como lista.')->send();
                    $livewire->dispatch('pipeline-updated');
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('No se pudo marcar la app como lista.')
                        ->body(implode("\n", Arr::flatten($exception->errors())))->send();
                }
            });
    }

    public static function markNotReady(): Action
    {
        return Action::make('markNotReady')->label('Marcar no lista')->authorize('update')->requiresConfirmation()
            ->modalDescription('La app desaparecerá del panel de editores en todas las campañas que la usan.')
            ->visible(fn (Pipeline $record): bool => $record->is_ready)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                app(PipelineActivation::class)->markNotReady($record);
                Notification::make()->success()->title('App marcada como no lista.')->send();
                $livewire->dispatch('pipeline-updated');
            });
    }
}
