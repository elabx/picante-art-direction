<?php

namespace App\Filament\Admin\Resources\Campaigns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]))
            ->columns([
                TextColumn::make('brand.name')->label('Marca'),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('pipelines_count')->label('Apps')->counts('pipelines'),
                TextColumn::make('pieces_count')->label('Piezas')->counts('pieces'),
            ])->filters([TrashedFilter::make()])->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make(), RestoreBulkAction::make()])]);
    }
}
