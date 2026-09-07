<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->revealable(false)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(8)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Select::make('role')
                    ->label('Rol')
                    ->options([
                        UserRole::ArtDirector->value => 'Director de arte',
                        UserRole::Editor->value => 'Editor',
                    ])
                    ->required()
                    ->live(),
                Select::make('brands')
                    ->label('Marcas')
                    ->relationship('brands', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->rule('array')
                    ->nestedRecursiveRule('exists:brands,id')
                    ->visible(fn (Get $get): bool => $get('role') === UserRole::Editor->value),
            ]);
    }
}
