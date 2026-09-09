<?php

namespace App\Filament\Admin\Resources\Campaigns\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GenerationsRelationManager extends RelationManager
{
    protected static string $relationship = 'generations';

    protected static ?string $title = 'Generaciones';

    private const STATUSES = ['pending' => 'Pendiente', 'submitting' => 'Enviando', 'submitted' => 'Enviada', 'processing' => 'Procesando', 'downloading' => 'Descargando', 'completed' => 'Completada', 'failed' => 'Fallida'];

    public function boot(): void
    {
        abort_unless(auth()->user()?->fresh()?->isArtDirector(), 403);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('Usuario'),
            TextColumn::make('execution_snapshot.pipeline_label')->label('App'),
            TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn ($state): string => ['series' => 'Serie', 'edit' => 'Edición', 'upscale' => 'Escalado'][$state->value]),
            TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state): string => self::STATUSES[$state->value]),
            TextColumn::make('failure_reason')->label('Motivo del fallo')->formatStateUsing(fn ($state): string => [
                'submission_unknown' => 'Envío incierto', 'poll_timeout' => 'Tiempo de espera agotado', 'provider_failed' => 'Fallo del proveedor',
                'invalid_result' => 'Resultado inválido', 'delivery_dimensions' => 'Dimensiones incorrectas', 'download_failed' => 'Fallo de descarga',
            ][$state->value]),
            TextColumn::make('error_message')->label('Mensaje de error')->wrap(),
            TextColumn::make('jobs.provider_job_id')->label('Trabajos del proveedor')->separator(', '),
            TextColumn::make('created_at')->label('Creada')->dateTime()->sortable(),
            TextColumn::make('completed_at')->label('Completada')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->label('Estado')->options(self::STATUSES)]);
    }
}
