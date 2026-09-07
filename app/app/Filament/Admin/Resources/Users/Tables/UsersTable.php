<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('email')->label('Correo electrónico')->searchable()->sortable(),
                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (UserRole|string $state): string => match ($state instanceof UserRole ? $state->value : $state) {
                        UserRole::ArtDirector->value => 'Director de arte',
                        UserRole::Editor->value => 'Editor',
                    }),
                TextColumn::make('brands.name')->label('Marcas')->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
