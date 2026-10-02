<?php

namespace App\Filament\Widgets;

use App\Filament\Admin\Resources\Brands\BrandResource;
use App\Models\Brand;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class BrandSummaryWidget extends TableWidget
{
    protected static ?string $heading = 'Resumen de marcas';

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Brand::query()->withCount(['users', 'campaigns'])->with('users:id,name'))
            ->defaultSort('name')
            ->recordUrl(fn (Brand $record): string => BrandResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Marca')->searchable()->sortable(),
                TextColumn::make('users.name')->label('Usuarios')->badge()->placeholder('Sin usuarios'),
                TextColumn::make('users_count')->label('Nº de usuarios')->numeric()->sortable(),
                TextColumn::make('campaigns_count')->label('Campañas')->numeric()->sortable(),
            ])
            ->emptyStateHeading('Todavía no hay marcas');
    }
}
