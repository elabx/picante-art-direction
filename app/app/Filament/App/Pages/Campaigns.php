<?php

namespace App\Filament\App\Pages;

use App\Models\Brand;
use App\Models\Campaign;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class Campaigns extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = '';

    protected static ?string $navigationLabel = 'Campañas';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected string $view = 'filament.app.pages.campaigns';

    public function mount(): void
    {
        $this->tenant();
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Campaign::query()
                ->where('brand_id', $this->tenant()->id)
                ->withCount([
                    'generations as series_count' => fn (Builder $query): Builder => $query
                        ->where('kind', 'series')
                        ->where('status', 'completed'),
                    'pieces',
                ]))
            ->columns([
                ImageColumn::make('cover_path')
                    ->label('Portada')
                    ->state(fn (Campaign $record): ?string => filled($record->cover_path) ? route('media.cover', $record) : null)
                    ->extraImgAttributes(['onerror' => $this->refreshImageOnError()]),
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('description')->label('Descripción')->limit(80),
                TextColumn::make('series_count')->label('Series'),
                TextColumn::make('pieces_count')->label('Piezas'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Abrir')
                    ->url(fn (Campaign $record): string => "/app/{$this->tenant()->slug}/campaigns/{$record->slug}"),
            ])
            ->emptyStateHeading('Todavía no hay campañas en esta marca.');
    }

    private function tenant(): Brand
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless($tenant instanceof Brand && $user !== null && $user->brands()->whereKey($tenant->id)->exists(), 403);

        return $tenant;
    }

    private function refreshImageOnError(): HtmlString
    {
        return new HtmlString("if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}");
    }
}
