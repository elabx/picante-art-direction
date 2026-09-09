<?php

namespace App\Filament\Admin\Resources\Pipelines\Tables;

use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Models\Pipeline;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PipelinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')->label('Nombre')->searchable(),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state): string => PipelineActions::KINDS[$state->value]),
                TextColumn::make('provider_ref')->label('Referencia del proveedor')->copyable(),
                IconColumn::make('is_ready')->label('Disponible')->boolean()
                    ->tooltip(fn (Pipeline $record): string => implode("\n", $record->readiness_errors ?? [])),
                TextColumn::make('campaigns_count')->label('Campañas')->counts('campaigns'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(PipelineActions::KINDS),
            ])
            ->recordActions([
                EditAction::make()->label('Configurar'),
                PipelineActions::refresh(),
                DeleteAction::make()->label('Eliminar'),
            ]);
    }
}
