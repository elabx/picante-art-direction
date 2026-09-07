<?php

namespace App\Filament\Admin\Resources\Pipelines\Actions;

use App\Engines\KreaException;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineActivation;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class PipelineActions
{
    public const KINDS = ['generator' => 'Generador', 'editor' => 'Editor', 'upscaler' => 'Upscaler'];

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

    public static function activate(): Action
    {
        return Action::make('activate')->label('Activar')->authorize('update')
            ->visible(fn (Pipeline $record): bool => ! $record->is_active)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                try {
                    app(PipelineActivation::class)->activate($record);
                    Notification::make()->success()->title('Flujo activado.')->send();
                    $livewire->dispatch('pipeline-updated');
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('No se pudo activar el flujo.')
                        ->body(implode("\n", Arr::flatten($exception->errors())))->send();
                }
            });
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')->label('Desactivar')->authorize('update')
            ->visible(fn (Pipeline $record): bool => $record->is_active)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                app(PipelineActivation::class)->deactivate($record);
                Notification::make()->success()->title('Flujo desactivado.')->send();
                $livewire->dispatch('pipeline-updated');
            });
    }
}
