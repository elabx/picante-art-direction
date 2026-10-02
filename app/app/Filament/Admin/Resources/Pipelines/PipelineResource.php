<?php

namespace App\Filament\Admin\Resources\Pipelines;

use App\Filament\Admin\Resources\Pipelines\Pages\EditPipeline;
use App\Filament\Admin\Resources\Pipelines\Pages\ListPipelines;
use App\Filament\Admin\Resources\Pipelines\RelationManagers\FieldsRelationManager;
use App\Filament\Admin\Resources\Pipelines\Schemas\PipelineForm;
use App\Filament\Admin\Resources\Pipelines\Tables\PipelinesTable;
use App\Models\Pipeline;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PipelineResource extends Resource
{
    protected static ?string $model = Pipeline::class;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'app del catálogo';

    protected static ?string $pluralModelLabel = 'Catálogo de apps';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Catálogo de apps';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return PipelineForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [FieldsRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return PipelinesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPipelines::route('/'), 'edit' => EditPipeline::route('/{record}/edit')];
    }
}
