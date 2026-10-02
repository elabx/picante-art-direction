<?php

namespace App\Filament\App\Pages;

use App\Enums\FailureReason;
use App\Enums\GenerationKind;
use App\Enums\GenerationStatus;
use App\Enums\PipelineKind;
use App\Enums\UserRole;
use App\Filament\Forms\Components\PrivateFileUpload;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Generation\CreateGeneration;
use App\Services\Generation\RestartGeneration;
use App\Services\Media\InputUploadService;
use App\Services\Pipelines\PipelineFormBuilder;
use App\Support\RestartGenerationAction;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class Generator extends Page
{
    protected static ?string $title = 'Generador';

    use RestrictsFileUploadsToSchemaComponents;

    protected static ?string $slug = 'campaigns/{campaign}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.pages.generator';

    #[Locked]
    public int $campaignId;

    #[Locked]
    public int $brandId;

    public ?int $pipelineId = null;

    #[Locked]
    public ?int $formRevision = null;

    #[Locked]
    public string $requestId;

    #[Locked]
    public string $restartRequestId;

    /** @var array<string, mixed> */
    public array $inputs = [];

    public function mount(mixed $campaign): void
    {
        $brand = $this->tenant();
        abort_unless(is_string($campaign), 404);
        $record = Campaign::query()->where('brand_id', $brand->id)->where('slug', $campaign)->firstOrFail();
        $this->brandId = $brand->id;
        $this->campaignId = $record->id;
        $generators = $record->activeGenerators()->get();
        $pipeline = $generators->firstWhere('id', $record->default_pipeline_id) ?? $generators->first();
        $this->pipelineId = $pipeline?->id;
        $this->formRevision = $pipeline?->config_revision;
        $this->requestId = (string) Str::uuid();
        $this->restartRequestId = (string) Str::uuid();
        $this->fillInputs([]);
    }

    public function hydrate(): void
    {
        $this->campaign();
    }

    public function getHeading(): string
    {
        return $this->campaign()->name;
    }

    public function getSubheading(): ?string
    {
        return $this->pipeline()?->label;
    }

    public function getBreadcrumbs(): array
    {
        return [Campaigns::getUrl(panel: 'app', tenant: $this->tenant()) => 'Campañas', $this->campaign()->name];
    }

    public function form(Schema $schema): Schema
    {
        $campaign = $this->campaign();
        $generators = $campaign->activeGenerators()->get();
        $pipeline = $this->pipeline();
        $components = $pipeline === null ? [] : app(PipelineFormBuilder::class)->components($pipeline);
        foreach ($components as $component) {
            if ($component instanceof PrivateFileUpload) {
                $component->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => InputUpload::query()
                    ->where('storage_path', $file)->where('user_id', $this->user()->id)
                    ->where('brand_id', $this->tenant()->id)->exists());
            }
        }

        return $schema->components([
            Select::make('pipelineId')->label('Generador')->options($generators->pluck('label', 'id'))
                ->live()->visible($generators->count() > 1 || ($pipeline === null && $generators->isNotEmpty())),
            Hidden::make('formRevision')->dehydrated(false),
            ...($pipeline === null ? [
                TextEntry::make('noGenerator')->hiddenLabel()->state('Esta campaña no tiene generador configurado.'),
            ] : [Section::make('1 · Qué quieres ver')->schema($components)]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('stats')->hiddenLabel()->state(function (): string {
                $campaign = $this->campaign();
                $pieces = $campaign->pieces()->count();
                $series = $campaign->generations()->where('kind', GenerationKind::Series)->where('status', GenerationStatus::Completed)->count();

                return $pieces ? "{$pieces} piezas en {$series} series" : 'sin piezas archivadas todavía';
            }),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                View::make('filament.app.partials.generator-form')->viewData(fn (): array => ['hasGenerator' => $this->pipeline() !== null]),
                View::make('filament.app.partials.generator-results')->viewData(fn (): array => $this->getViewData()),
            ]),
        ]);
    }

    public function updatedPipelineId(): void
    {
        if ($this->pipelineId !== null) {
            abort_unless($this->campaign()->pipelines()->where('pipelines.kind', PipelineKind::Generator)->whereKey($this->pipelineId)->exists(), 404);
        }
        $pipeline = $this->pipeline();
        $this->formRevision = $pipeline?->config_revision;
        $this->resetValidation();
        $this->fillInputs([]);
    }

    public function generate(): void
    {
        $campaign = $this->campaign();
        $pipeline = $this->pipeline();
        try {
            if ($pipeline === null) {
                throw ValidationException::withMessages(['generation' => 'Esta campaña no tiene generador configurado.']);
            }
            if (Generation::query()->where('request_id', $this->requestId)->exists()) {
                $existing = app(CreateGeneration::class)->series($this->user(), $campaign, $pipeline, [], $this->requestId, $this->formRevision);
                $uiInputs = $this->inputs;
                foreach ($this->form->getFlatFields() as $field) {
                    $name = Str::after($field->getName(), 'inputs.');
                    $value = $existing->execution_snapshot['inputs'][$name] ?? null;
                    if ($field instanceof PrivateFileUpload && is_array($value) && isset($value['__upload'])) {
                        $upload = app(InputUploadService::class)->authorize($value['__upload'], $this->tenant(), $this->user());
                        $uiInputs[$name] = $upload->storage_path;
                    }
                }
                $this->fillInputs($uiInputs);
                $this->seriesSubmitted();

                return;
            }
            if ($pipeline->config_revision !== $this->formRevision) {
                throw ValidationException::withMessages(['generation' => 'La configuración cambió. Recarga el formulario.']);
            }
            $this->validateUploadShapes();
            $this->validate(app(PipelineFormBuilder::class)->rules($pipeline));
            $state = $this->form->getState();
            $inputs = $state['inputs'] ?? [];
            $uiInputs = $inputs;
            foreach ($this->form->getFlatFields() as $field) {
                if (! $field instanceof PrivateFileUpload) {
                    continue;
                }
                $name = Str::after($field->getName(), 'inputs.');
                $path = $inputs[$name] ?? null;
                if (blank($path)) {
                    continue;
                }
                $uploads = app(InputUploadService::class);
                $upload = InputUpload::query()->where('storage_path', $path)->first();
                $upload = $upload === null
                    ? $uploads->finalize($path, $this->tenant(), $this->user())
                    : $uploads->authorize($upload->id, $this->tenant(), $this->user());
                $inputs[$name] = $upload->id;
                $uiInputs[$name] = $upload->storage_path;
                $this->fillInputs($uiInputs);
            }
            app(CreateGeneration::class)->series($this->user(), $campaign, $pipeline, $inputs, $this->requestId, $this->formRevision);
            $this->seriesSubmitted();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            throw $exception;
        }
    }

    public function checkStatus(int $generationId): void
    {
        $generation = $this->generation($generationId);
        abort_unless($generation->status === GenerationStatus::Failed && $generation->retryable
            && in_array($generation->failure_reason, [FailureReason::PollTimeout, FailureReason::DownloadFailed], true), 403);
        app(RestartGeneration::class)->checkStatus($this->user(), $generation);
    }

    public function restartAction(): Action
    {
        return RestartGenerationAction::make(fn (array $arguments): Generation => $this->generation((int) ($arguments['generationId'] ?? 0)))
            ->action(function (array $arguments): void {
                try {
                    app(RestartGeneration::class)->confirmRestart(
                        $this->user(), $this->generation((int) ($arguments['generationId'] ?? 0)), $this->restartRequestId,
                    );
                    $this->restartRequestId = (string) Str::uuid();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    #[On('jobs-updated')]
    public function refreshResults(): void
    {
        $this->campaign();
    }

    protected function getViewData(): array
    {
        $campaign = $this->campaign();
        $latest = $campaign->generations()->where('kind', GenerationKind::Series)
            ->where('status', GenerationStatus::Completed)->orderByDesc('completed_at')->orderByDesc('id')->first();

        return [
            'running' => $campaign->generations()->nonTerminal()->with(['jobs' => fn ($query) => $query->orderBy('id')])->latest('id')->get(),
            'latestPieces' => $latest?->pieces()->orderBy('index')->orderBy('id')->limit(4)->get() ?? collect(),
            'previousPieces' => $campaign->pieces()->when($latest, fn ($query) => $query->where('generation_id', '!=', $latest->id))->latest('id')->limit(25)->get(),
            'failure' => $campaign->generations()->where('status', GenerationStatus::Failed)->latest('id')->first(),
            'galleryUrl' => "/app/{$this->tenant()->slug}/campaigns/{$campaign->slug}/gallery",
        ];
    }

    private function user(): User
    {
        $user = auth()->user()?->fresh();
        abort_unless($user instanceof User && $user->role === UserRole::Editor, 403);

        return $user;
    }

    private function tenant(): Brand
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Brand && $this->user()->brands()->whereKey($tenant->id)->exists(), 403);
        abort_if(isset($this->brandId) && $this->brandId !== $tenant->id, 403);

        return $tenant;
    }

    private function campaign(): Campaign
    {
        return Campaign::query()->where('brand_id', $this->tenant()->id)->findOrFail($this->campaignId);
    }

    private function pipeline(): ?Pipeline
    {
        $campaign = $this->campaign();
        if ($this->pipelineId === null) {
            return null;
        }
        $pipeline = $campaign->pipelines()->where('pipelines.kind', PipelineKind::Generator)->find($this->pipelineId);

        return $pipeline?->is_ready ? $pipeline : null;
    }

    private function generation(int $id): Generation
    {
        return $this->campaign()->generations()->find($id) ?? abort(404);
    }

    /** @param array<string, mixed> $inputs */
    private function fillInputs(array $inputs): void
    {
        $this->inputs = [];
        $this->form->fill(['inputs' => $inputs, 'pipelineId' => $this->pipelineId, 'formRevision' => $this->formRevision]);
    }

    private function validateUploadShapes(): void
    {
        foreach ($this->form->getFlatFields() as $field) {
            if (! $field instanceof PrivateFileUpload) {
                continue;
            }
            $value = data_get($this, $field->getName());
            if ($value === null || $value === []) {
                continue;
            }
            if (! is_array($value) || count($value) > 1
                || collect($value)->contains(fn ($file): bool => ! is_string($file) && ! $file instanceof TemporaryUploadedFile)) {
                throw ValidationException::withMessages([$field->getName() => 'El archivo no es válido.']);
            }
        }
    }

    private function seriesSubmitted(): void
    {
        $this->requestId = (string) Str::uuid();
        Notification::make()->success()->title('Serie enviada. Aparecerá aquí cuando termine.')->send();
    }
}
