<?php

namespace App\Filament\Admin\Resources\Brands\Schemas;

use App\Filament\Forms\Components\PrivateFileUpload;
use App\Models\Brand;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $old, ?string $state, Get $get, Set $set): void {
                        $slug = $get('slug');

                        if (blank($slug) || $slug === Str::slug($old)) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                PrivateFileUpload::make('logo_path')
                    ->label('Logotipo')
                    ->disk('pieces')
                    ->directory('brands')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(20 * 1024)
                    ->visibility('private'),
                TextInput::make('krea_api_key')
                    ->label('Clave de API de Krea')
                    ->password()
                    ->revealable(false)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Se guarda cifrada. Escribe una nueva para reemplazarla.')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (filled($state)) {
                            $set('use_studio_key', false);
                        }
                    }),
                Toggle::make('use_studio_key')
                    ->label('Usar la clave del estudio')
                    ->default(fn (?Brand $record): bool => $record === null || blank($record->krea_api_key))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (bool $state, Set $set): void {
                        if ($state) {
                            $set('krea_api_key', null);
                        }
                    }),
                TextEntry::make('krea_key_source')
                    ->label('Clave Krea')
                    ->state(fn (?Brand $record): string => $record?->resolveKreaKeySource() === 'brand'
                        ? 'Clave propia configurada'
                        : 'Usa la clave del estudio'),
            ]);
    }
}
