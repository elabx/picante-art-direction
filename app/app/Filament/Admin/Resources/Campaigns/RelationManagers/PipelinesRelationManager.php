<?php

namespace App\Filament\Admin\Resources\Campaigns\RelationManagers;

use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use App\Models\Pipeline;
use App\Services\Pipelines\CampaignPipelineAssignment;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PipelinesRelationManager extends RelationManager
{
    protected static string $relationship = 'pipelines';

    protected static ?string $title = 'Apps';

    public function boot(): void
    {
        abort_unless(auth()->user()?->fresh()?->isArtDirector(), 403);
    }

    /** @return array<int, string> */
    public function assignableOptions(): array
    {
        return Pipeline::query()
            ->where('is_ready', true)
            ->whereDoesntHave('campaigns', fn ($query) => $query->whereKey($this->getOwnerRecord()->getKey()))
            ->orderBy('kind')->orderBy('label')
            ->get()
            ->mapWithKeys(fn (Pipeline $pipeline): array => [$pipeline->id => PipelineActions::KINDS[$pipeline->kind->value].' · '.$pipeline->label])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('label')->columns([
            TextColumn::make('pivot.sort_order')->label('Orden'),
            TextColumn::make('label')->label('Nombre'),
            TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state): string => PipelineActions::KINDS[$state->value]),
            TextColumn::make('provider_ref')->label('Referencia del proveedor'),
            IconColumn::make('is_ready')->label('Lista')->boolean()
                ->tooltip(fn (Pipeline $record): string => implode("\n", $record->readiness_errors ?? [])),
        ])->headerActions([
            Action::make('assign')->label('Asignar app')->modalHeading('Asignar app del catálogo')
                ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                ->schema([
                    Select::make('pipeline_id')->label('App')->required()->searchable()
                        ->options(fn (): array => $this->assignableOptions())
                        ->helperText('Solo aparecen apps listas que aún no están asignadas.'),
                    TextInput::make('sort_order')->label('Orden')->integer()->minValue(0)->default(0)->required(),
                ])
                ->action(function (array $data): void {
                    $pipeline = Pipeline::query()->find($data['pipeline_id']);
                    if ($pipeline === null) {
                        $this->failAssign(['La app no está disponible.']);
                    }
                    try {
                        app(CampaignPipelineAssignment::class)->assign($this->getOwnerRecord(), $pipeline, (int) $data['sort_order']);
                    } catch (ValidationException $exception) {
                        $this->failAssign(Arr::flatten($exception->errors()));
                    }
                    Notification::make()->success()->title('App asignada.')->send();
                }),
        ])->recordActions([
            Action::make('reorder')->label('Cambiar orden')
                ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                ->fillForm(fn (Pipeline $record): array => ['sort_order' => (int) $record->pivot->sort_order])
                ->schema([TextInput::make('sort_order')->label('Orden')->integer()->minValue(0)->required()])
                ->action(function (Pipeline $record, array $data): void {
                    app(CampaignPipelineAssignment::class)->reorder($this->getOwnerRecord(), $record, (int) $data['sort_order']);
                }),
            Action::make('openCatalog')->label('Abrir en catálogo')
                ->url(fn (Pipeline $record): string => PipelineResource::getUrl('edit', ['record' => $record])),
            Action::make('remove')->label('Quitar')->color('danger')->requiresConfirmation()
                ->modalDescription('La app dejará de estar disponible en esta campaña. Los resultados ya generados se conservan.')
                ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                ->action(function (Pipeline $record): void {
                    app(CampaignPipelineAssignment::class)->remove($this->getOwnerRecord(), $record);
                    Notification::make()->success()->title('App quitada de la campaña.')->send();
                }),
        ]);
    }

    /** @param list<string> $messages */
    private function failAssign(array $messages): never
    {
        throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.pipeline_id' => $messages]);
    }
}
