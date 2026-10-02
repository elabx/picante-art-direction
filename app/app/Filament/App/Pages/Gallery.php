<?php

namespace App\Filament\App\Pages;

use App\Enums\PieceKind;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\Piece;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class Gallery extends Page implements HasTable
{
    protected static ?string $title = 'Galería';

    use InteractsWithTable;

    protected static ?string $slug = 'campaigns/{campaign}/gallery';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.app.pages.gallery';

    #[Locked]
    public int $campaignId;

    #[Locked]
    public int $brandId;

    public function mount(mixed $campaign): void
    {
        $brand = $this->tenant();
        abort_unless(is_string($campaign), 404);

        $record = Campaign::query()->where('brand_id', $brand->id)->where('slug', $campaign)->firstOrFail();
        $this->brandId = $brand->id;
        $this->campaignId = $record->id;
    }

    public function hydrate(): void
    {
        $this->campaign();
    }

    public function getHeading(): string
    {
        return $this->campaign()->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            Campaigns::getUrl(panel: 'app', tenant: $this->tenant()) => 'Campañas',
            "/app/{$this->tenant()->slug}/campaigns/{$this->campaign()->slug}" => $this->campaign()->name,
            'Galería',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Piece::query()
                ->where('campaign_id', $this->campaign()->id)
                ->with('generation'))
            ->columns([
                ImageColumn::make('image')
                    ->label('Imagen')
                    ->state(fn (Piece $record): string => route('media.piece', $record)),
                TextColumn::make('series_version')
                    ->label('Serie / versión')
                    ->state(fn (Piece $record): string => $this->seriesVersionLabel($record)),
                TextColumn::make('dimensions')
                    ->label('Dimensiones')
                    ->state(fn (Piece $record): string => "{$record->width} × {$record->height}"),
                IconColumn::make('is_4k')->label('4K')->boolean(),
                IconColumn::make('selected')->label('Marcada')->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Mostrar')
                    ->options([
                        'all' => 'Todas',
                        'original' => 'Series',
                        'edit' => 'Ediciones',
                        '4k' => '4K',
                        'selected' => 'Marcadas',
                    ])
                    ->default('all')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? 'all') {
                            'original' => $query->where('kind', PieceKind::Original->value),
                            'edit' => $query->where('kind', PieceKind::Edit->value),
                            '4k' => $query->where('is_4k', true),
                            'selected' => $query->where('selected', true),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Ver')
                    ->action(fn (Piece $record): mixed => $this->dispatch('open-piece', pieceId: $record->id)),
                Action::make('download')
                    ->label('Descargar')
                    ->url(fn (Piece $record): string => route('media.piece', [$record, 'download' => 1])),
            ])
            ->headerActions([
                Action::make('generateMore')
                    ->label('Generar más')
                    ->url(fn (): string => "/app/{$this->tenant()->slug}/campaigns/{$this->campaign()->slug}"),
            ])
            ->emptyStateHeading('La galería está vacía. Genera una serie para empezar.')
            ->emptyStateActions([
                Action::make('generate')
                    ->label('Generar más')
                    ->url(fn (): string => "/app/{$this->tenant()->slug}/campaigns/{$this->campaign()->slug}"),
            ]);
    }

    #[On('jobs-updated')]
    public function refreshGallery(): void
    {
        $this->campaign();
    }

    private function seriesVersionLabel(Piece $piece): string
    {
        if ($piece->kind === PieceKind::Original) {
            $generation = $piece->generation;
            abort_unless($generation instanceof Generation && $generation->campaign_id === $this->campaign()->id, 403);

            $position = Generation::query()
                ->where('campaign_id', $this->campaign()->id)
                ->where('kind', 'series')
                ->where(function (Builder $query) use ($generation): void {
                    $query->where('created_at', '<', $generation->created_at)
                        ->orWhere(function (Builder $query) use ($generation): void {
                            $query->where('created_at', $generation->created_at)->where('id', '<=', $generation->id);
                        });
                })
                ->count();

            return "S{$position}";
        }

        $rootId = $piece->rootId();
        $position = Piece::query()
            ->where('campaign_id', $this->campaign()->id)
            ->where(function (Builder $query) use ($rootId): void {
                $query->whereKey($rootId)->orWhere('root_piece_id', $rootId);
            })
            ->where(function (Builder $query) use ($piece): void {
                $query->where('created_at', '<', $piece->created_at)
                    ->orWhere(function (Builder $query) use ($piece): void {
                        $query->where('created_at', $piece->created_at)->where('id', '<=', $piece->id);
                    });
            })
            ->count();

        return "v{$position}";
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
}
