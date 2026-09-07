<?php

namespace App\Filament\Admin\Resources\Pipelines;

use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Filament\Admin\Resources\Pipelines\Pages\EditPipeline;
use App\Filament\Admin\Resources\Pipelines\RelationManagers\FieldsRelationManager;
use App\Filament\Admin\Resources\Pipelines\Schemas\PipelineForm;
use App\Models\Pipeline;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class PipelineResource extends Resource
{
    protected static ?string $model = Pipeline::class;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'flujo';

    protected static ?string $pluralModelLabel = 'Flujos';

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return PipelineForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [FieldsRelationManager::class];
    }

    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return CampaignResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }

    public static function getPages(): array
    {
        return ['edit' => EditPipeline::route('/{record}/edit')];
    }
}
