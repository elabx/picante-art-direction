<?php

namespace App\Livewire;

use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\User;
use App\Services\Generation\CreateGeneration;
use App\Services\Generation\FourKRule;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class PieceViewer extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $brandId;

    #[Locked]
    public ?int $pieceId = null;

    #[Locked]
    public int $latestChildId = 0;

    #[Locked]
    public string $editRequestId;

    #[Locked]
    public string $upscaleRequestId;

    #[Locked]
    public string $restartRequestId;

    public string $instruction = '';

    public function mount(): void
    {
        $this->brandId = $this->tenant()->id;
        $this->editRequestId = (string) Str::uuid();
        $this->upscaleRequestId = (string) Str::uuid();
        $this->restartRequestId = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        $this->tenant();
        if ($this->pieceId !== null) {
            $this->piece();
        }
    }

    #[On('open-piece')]
    public function open(int $pieceId): void
    {
        $piece = $this->authorizedPiece($pieceId);
        $this->viewPiece($piece);
        $this->dispatch('open-modal', id: 'piece-viewer');
    }

    public function selectVersion(int $pieceId): void
    {
        $piece = $this->authorizedPiece($pieceId);
        abort_unless($this->versions($this->piece())->contains('id', $piece->id), 403);
        $this->viewPiece($piece);
    }

    public function applyEdit(): void
    {
        $piece = $this->piece();
        try {
            app(CreateGeneration::class)->edit($this->user(), $piece, $this->instruction, $this->editRequestId);
            $this->editRequestId = (string) Str::uuid();
            Notification::make()->success()->title('Edición enviada.')->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            throw $exception;
        }
    }

    public function upscaleAction(): Action
    {
        return Action::make('upscale')->label('Entregar en 4K')->color('gray')
            ->disabled(fn (): bool => $this->pieceId === null || ! $this->campaign()->activeUpscaler()?->isReady())
            ->requiresConfirmation()->modalHeading('Entregar en 4K')
            ->modalDescription('Se generará una versión con 3.840 píxeles en el lado mayor, conservando la proporción.')
            ->action(function (): void {
                try {
                    app(CreateGeneration::class)->upscale($this->user(), $this->piece(), $this->upscaleRequestId);
                    $this->upscaleRequestId = (string) Str::uuid();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    throw $exception;
                }
            });
    }

    public function toggleSelected(): void
    {
        $piece = $this->piece();
        $piece->forceFill(['selected' => ! $piece->selected])->save();
    }

    public function download(): RedirectResponse
    {
        return redirect()->away(route('media.piece', [$this->piece(), 'download' => 1]));
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

    #[On('jobs-updated')]
    public function refreshResults(): void
    {
        $this->tenant();
        if ($this->pieceId === null) {
            return;
        }
        $piece = $this->piece();
        $child = Piece::query()->where('campaign_id', $piece->campaign_id)->where('parent_piece_id', $piece->id)
            ->where('id', '>', $this->latestChildId)->latest('id')->first();
        if ($child !== null) {
            $this->viewPiece($child);
        }
    }

    public function render(): View
    {
        $this->tenant();
        $piece = $this->pieceId === null ? null : $this->piece();
        $campaign = $piece === null ? null : $this->campaign();

        return view('livewire.piece-viewer', [
            'piece' => $piece,
            'versions' => $piece === null ? collect() : $this->versions($piece),
            'originatingInputs' => $piece === null ? [] : $this->originatingInputs($piece),
            'hasEditor' => $campaign?->activeEditor()?->isReady() ?? false,
            'hasUpscaler' => $campaign?->activeUpscaler()?->isReady() ?? false,
            'meetsTarget' => $piece !== null && FourKRule::meetsTarget($piece->width, $piece->height),
            'running' => $piece !== null && $this->generations()->nonTerminal()->exists(),
            'failures' => $piece === null ? collect() : $this->generations()->where('status', GenerationStatus::Failed)->latest('id')->get(),
        ]);
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

    private function authorizedPiece(int $id): Piece
    {
        $brand = $this->tenant();
        $piece = Piece::query()->find($id) ?? abort(403);
        abort_unless(Campaign::withTrashed()->where('brand_id', $brand->id)->whereKey($piece->campaign_id)->exists(), 403);

        return $piece;
    }

    private function piece(): Piece
    {
        return $this->authorizedPiece($this->pieceId ?? 0);
    }

    private function campaign(): Campaign
    {
        return Campaign::withTrashed()->where('brand_id', $this->tenant()->id)->findOrFail($this->piece()->campaign_id);
    }

    private function versions(Piece $piece): Collection
    {
        $root = Piece::query()->where('campaign_id', $piece->campaign_id)->find($piece->rootId()) ?? abort(403);

        return Piece::query()->where('campaign_id', $piece->campaign_id)
            ->where(fn (Builder $query) => $query->whereKey($root->id)->orWhere('root_piece_id', $root->id))
            ->orderBy('created_at')->orderBy('id')->get();
    }

    private function generations(): Builder
    {
        $piece = $this->piece();

        return Generation::query()->where('campaign_id', $piece->campaign_id)->whereIn('kind', ['edit', 'upscale'])
            ->whereIn('parent_piece_id', $this->versions($piece)->pluck('id'));
    }

    private function generation(int $id): Generation
    {
        return $this->generations()->find($id) ?? abort(404);
    }

    private function viewPiece(Piece $piece): void
    {
        $this->versions($piece);
        $this->pieceId = $piece->id;
        $this->latestChildId = (int) Piece::query()->where('campaign_id', $piece->campaign_id)->where('parent_piece_id', $piece->id)->max('id');
        $this->instruction = '';
        $this->resetValidation();
    }

    /** @return list<array{label: string, text?: string, url?: string}> */
    private function originatingInputs(Piece $piece): array
    {
        $generation = $piece->generation;
        abort_unless($generation?->campaign_id === $piece->campaign_id, 403);
        $snapshot = $generation->execution_snapshot;
        $inputs = [];
        foreach ($snapshot['inputs'] ?? [] as $name => $value) {
            $label = (string) ($snapshot['labels'][$name] ?? $name);
            if (is_scalar($value)) {
                $inputs[] = ['label' => $label, 'text' => (string) $value];
            } elseif (is_array($value) && isset($value['__piece']) && is_int($value['__piece'])) {
                $source = Piece::query()->whereHas('campaign', fn (Builder $query) => $query->withTrashed()->where('brand_id', $this->tenant()->id))->find($value['__piece']);
                if ($source !== null) {
                    $inputs[] = ['label' => $label, 'url' => route('media.piece', $source)];
                }
            } elseif (is_array($value) && isset($value['__upload']) && is_int($value['__upload'])) {
                $upload = InputUpload::query()->where('brand_id', $this->tenant()->id)->find($value['__upload']);
                if ($upload !== null && ($upload->user_id === $this->user()->id
                    || ($generation->pipeline?->campaign_id === $piece->campaign_id && $generation->pipeline->inputUploads()->whereKey($upload->id)->exists()))) {
                    $inputs[] = ['label' => $label, 'url' => route('media.upload', $upload)];
                }
            }
        }

        return $inputs;
    }
}
