<?php

namespace App\Livewire;

use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generation\RestartGeneration;
use App\Support\RestartGenerationAction;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class JobsBell extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $brandId;

    #[Locked]
    public int $unseen = 0;

    /** @var list<int> */
    #[Locked]
    public array $displayedIds = [];

    #[Locked]
    public string $restartRequestId;

    public function mount(): void
    {
        $this->brandId = $this->tenant()->id;
        $this->restartRequestId = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        $this->tenant();
    }

    public function markSeen(): void
    {
        $this->query()->whereIn('id', $this->displayedIds)->whereNull('seen_at')
            ->whereIn('status', ['completed', 'failed'])->update(['seen_at' => now()]);
    }

    public function poll(): void
    {
        $this->tenant();
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
                    app(RestartGeneration::class)->confirmRestart($this->user(), $this->generation((int) ($arguments['generationId'] ?? 0)), $this->restartRequestId);
                    $this->restartRequestId = (string) Str::uuid();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    public function render(): View
    {
        $jobs = $this->query()->with(['pieces' => fn ($query) => $query->orderBy('index')->orderBy('id')->limit(3)])
            ->latest()->latest('id')->limit(30)->get();
        $this->displayedIds = $jobs->modelKeys();
        $this->unseen = $jobs->filter(fn (Generation $job): bool => $job->status->isTerminal() && $job->seen_at === null)->count();
        $rows = $jobs->map(function (Generation $job): array {
            $job->setRelation('pieces', $job->pieces->where('campaign_id', $job->campaign_id));
            $snapshot = $job->execution_snapshot;
            $prompt = $snapshot['inputs'][$snapshot['bindings']['prompt'] ?? ''] ?? null;
            $images = 0;
            $inputs = $snapshot['inputs'] ?? [];
            array_walk_recursive($inputs, function (mixed $value, string|int $key) use (&$images): void {
                if (in_array($key, ['__upload', '__piece'], true)) {
                    $images++;
                }
            });

            return ['generation' => $job, 'summary' => is_scalar($prompt) && filled($prompt) ? Str::limit((string) $prompt, 100) : "{$images} imágenes"];
        });
        $this->dispatch('jobs-updated');

        return view('livewire.jobs-bell', ['rows' => $rows]);
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

    private function query(): Builder
    {
        return Generation::query()->where('user_id', $this->user()->id)
            ->whereHas('campaign', fn (Builder $query) => $query->where('brand_id', $this->tenant()->id));
    }

    private function generation(int $id): Generation
    {
        return $this->query()->whereIn('id', $this->displayedIds)->find($id) ?? abort(404);
    }
}
