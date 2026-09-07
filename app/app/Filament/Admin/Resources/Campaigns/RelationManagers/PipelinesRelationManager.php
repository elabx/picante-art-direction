<?php

namespace App\Filament\Admin\Resources\Campaigns\RelationManagers;

use App\Engines\KreaException;
use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PipelinesRelationManager extends RelationManager
{
    protected static string $relationship = 'pipelines';

    protected static ?string $title = 'Flujos';

    public function boot(): void
    {
        abort_unless(auth()->user()?->fresh()?->isArtDirector(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label('Tipo')->options(PipelineActions::KINDS)->required(),
            TextInput::make('label')->label('Nombre')->required()->maxLength(255),
            TextInput::make('provider_ref')->label('Referencia del proveedor')->required()->maxLength(255)->regex('/\A[A-Za-z0-9_.:\/-]+\z/'),
            TextInput::make('sort_order')->label('Orden')->integer()->minValue(0)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('label')->columns([
            TextColumn::make('label')->label('Nombre'),
            TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state): string => PipelineActions::KINDS[$state->value]),
            TextColumn::make('provider_ref')->label('Referencia del proveedor'),
            IconColumn::make('is_active')->label('Activo')->boolean(),
            IconColumn::make('readiness')->label('Configuración')->state(fn (Pipeline $record): bool => empty($record->readiness_errors))
                ->boolean()->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-exclamation-triangle')->falseColor('warning')
                ->tooltip(fn (Pipeline $record): string => implode("\n", $record->readiness_errors ?? [])),
        ])->headerActions([
            CreateAction::make()->databaseTransaction(false)
                ->before(fn () => Gate::authorize('create', Pipeline::class))
                ->after(function (Pipeline $record): void {
                    try {
                        app(PipelineSchemaSync::class)->sync($record);
                    } catch (KreaException $exception) {
                        $record->delete();
                        throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.provider_ref' => $exception->getMessage()]);
                    }
                }),
        ])->recordActions([
            PipelineActions::refresh(), PipelineActions::activate(), PipelineActions::deactivate(),
            Action::make('editFields')->label('Editar campos')->authorize('update')
                ->url(fn (Pipeline $record): string => PipelineResource::getUrl('edit', ['record' => $record])),
        ]);
    }
}
