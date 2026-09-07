<?php

namespace App\Filament\Admin\Resources\Pipelines\RelationManagers;

use App\Filament\Forms\Components\PrivateFileUpload;
use App\Models\PipelineField;
use App\Services\Pipelines\PipelineFieldConfiguration;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class FieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    protected static ?string $title = 'Campos';

    public function boot(): void
    {
        abort_unless(auth()->user()?->fresh()?->isArtDirector(), 403);
    }

    #[On('pipeline-updated')]
    public function refreshFields(): void {}

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('input_type')->label('Tipo de entrada')->required()
                ->options(['string' => 'Texto', 'image' => 'Imagen', 'integer' => 'Entero', 'number' => 'Número', 'boolean' => 'Booleano', 'unknown' => 'Desconocido'])
                ->live()->afterStateUpdated(fn (Select $component) => $component->getContainer()->getComponent('fixedValue')->getChildSchema()->fill()),
            TextInput::make('label_override')->label('Etiqueta')->maxLength(255),
            TextInput::make('help_text')->label('Texto de ayuda'),
            Select::make('visibility')->label('Visibilidad')->options(['visible' => 'Visible', 'hidden' => 'Oculto'])->required(),
            Toggle::make('has_fixed_value')->label('Usar valor fijo')->live(),
            Group::make()->key('fixedValue')->schema(fn (Get $get): array => [
                $get('input_type') === 'image'
                    ? PrivateFileUpload::make('fixed_value')->label('Valor fijo')->disk('inputs')->directory('tmp')->visibility('private')
                        ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(20 * 1024)
                        ->preventFilePathTampering(allowFilePathUsing: fn (string $file, PipelineField $record): bool => (str_starts_with($file, 'tmp/') && PrivateFileUpload::isCanonicalPath($file))
                            || $file === app(PipelineFieldConfiguration::class)->existingImagePath($record))
                    : TextInput::make('fixed_value')->label('Valor fijo')->helperText('JSON válido o texto.'),
            ])->visible(fn (Get $get): bool => (bool) $get('has_fixed_value')),
            Select::make('role')->label('Vinculación')->options(['none' => 'Ninguno', 'prompt' => 'Prompt', 'image' => 'Imagen'])->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->reorder()->orderBy('stale')->orderBy('sort_order')->orderBy('id'))
            ->columns([
                TextColumn::make('name')->label('Nombre'),
                IconColumn::make('required')->label('Obligatorio')->boolean(),
                TextColumn::make('source_schema')->label('Esquema de origen')
                    ->state(fn (PipelineField $record): string => is_array($record->source_schema['type'] ?? null)
                        ? implode(' / ', $record->source_schema['type']) : ($record->source_schema['type'] ?? 'Desconocido'))
                    ->tooltip(fn (PipelineField $record): string => json_encode($record->source_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                IconColumn::make('needs_configuration')->label('Requiere configuración')->boolean()->trueIcon('heroicon-o-exclamation-triangle')->trueColor('warning'),
            ])->recordActions([
                EditAction::make()->authorize('update')->databaseTransaction(false)
                    ->mutateRecordDataUsing(fn (PipelineField $record): array => app(PipelineFieldConfiguration::class)->formData($record))
                    ->using(function (PipelineField $record, array $data): PipelineField {
                        try {
                            return app(PipelineFieldConfiguration::class)->save($this->getOwnerRecord(), $record, $data, auth()->user());
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.fixed_value' => $exception->validator->errors()->all()]);
                        }
                    })->after(fn () => $this->dispatch('pipeline-updated')),
            ]);
    }
}
