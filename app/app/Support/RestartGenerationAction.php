<?php

namespace App\Support;

use App\Enums\FailureReason;
use App\Models\Generation;
use Closure;
use Filament\Actions\Action;

final class RestartGenerationAction
{
    /** @param Closure(array): Generation $generation */
    public static function make(Closure $generation): Action
    {
        return Action::make('restart')->label('Generar de nuevo')->requiresConfirmation()
            ->modalHeading('¿Iniciar una nueva generación?')
            ->modalDescription(function (array $arguments) use ($generation): string {
                return ($generation($arguments)->failure_reason === FailureReason::ProviderFailed
                    ? 'El trabajo anterior terminó sin completarse.' : 'El trabajo anterior podría seguir en curso.')
                    .' Iniciar una nueva generación puede generar un cargo adicional. Intentaremos recuperar el resultado anterior cuando sea posible.';
            })
            ->modalSubmitActionLabel('Sí, generar de nuevo')->modalCancelActionLabel('Cancelar');
    }
}
