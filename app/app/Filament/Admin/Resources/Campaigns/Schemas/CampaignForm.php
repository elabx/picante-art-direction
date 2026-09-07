<?php

namespace App\Filament\Admin\Resources\Campaigns\Schemas;

use App\Models\Campaign;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Marca')->relationship('brand', 'name')->searchable()->preload()->required()->disabledOn('edit'),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(function (?string $old, ?string $state, Get $get, Set $set): void {
                    if (blank($get('slug')) || $get('slug') === Str::slug($old)) {
                        $set('slug', Str::slug($state));
                    }
                }),
            TextInput::make('slug')->label('Slug')->required()->maxLength(255)
                ->unique(modifyRuleUsing: fn (Unique $rule, Get $get, ?Campaign $record): Unique => $rule->where('brand_id', $record?->brand_id ?? $get('brand_id'))),
            Textarea::make('description')->label('Descripción')->columnSpanFull(),
            FileUpload::make('cover_path')->label('Portada')->disk('pieces')->directory('covers')->visibility('private')
                ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(20 * 1024),
            DatePicker::make('starts_on')->label('Fecha de inicio'),
            DatePicker::make('ends_on')->label('Fecha de fin')->afterOrEqual('starts_on'),
            Select::make('default_pipeline_id')->label('Generador por defecto')->hiddenOn('create')
                ->options(fn (?Campaign $record): array => $record?->activeGenerators()->pluck('label', 'id')->all() ?? []),
        ]);
    }
}
