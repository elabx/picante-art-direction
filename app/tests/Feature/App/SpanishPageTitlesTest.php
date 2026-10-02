<?php

use App\Filament\Admin\Resources\Brands\BrandResource;
use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\App\Pages\Campaigns;
use App\Filament\App\Pages\Gallery;
use App\Filament\App\Pages\Generator;
use App\Filament\App\Pages\Settings;

it('titles every editor page in Spanish', function (string $page, string $title): void {
    expect((new $page)->getTitle())->toBe($title);
})->with([
    [Campaigns::class, 'Campañas'],
    [Generator::class, 'Generador'],
    [Gallery::class, 'Galería'],
    [Settings::class, 'Ajustes'],
]);

it('keeps Spanish sentence case in admin headings such as Crear marca', function (string $resource, string $plural, string $singular): void {
    expect($resource::getTitleCasePluralModelLabel())->toBe($plural)
        ->and($resource::getTitleCaseModelLabel())->toBe($singular);
})->with([
    [BrandResource::class, 'Marcas', 'marca'],
    [CampaignResource::class, 'Campañas', 'campaña'],
    [PipelineResource::class, 'Catálogo de apps', 'app del catálogo'],
    [UserResource::class, 'Usuarios', 'usuario'],
]);
