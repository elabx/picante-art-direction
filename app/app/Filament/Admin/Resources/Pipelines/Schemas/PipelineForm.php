<?php

namespace App\Filament\Admin\Resources\Pipelines\Schemas;

use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Models\Pipeline;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PipelineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('label')->label('Nombre'),
            TextEntry::make('kind')->label('Tipo')->state(fn (Pipeline $record): string => PipelineActions::KINDS[$record->kind->value]),
            TextEntry::make('provider_ref')->label('Referencia del proveedor'),
            TextEntry::make('config_revision')->label('Revisión de configuración'),
            TextEntry::make('readiness_errors')->label('Errores de configuración')->listWithLineBreaks()->columnSpanFull(),
        ]);
    }
}
