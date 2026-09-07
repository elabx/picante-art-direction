<?php

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Pipelines\PipelineFormBuilder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;

it('renders each supported type, orders the prompt first, and labels fields', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'hd', 'input_type' => InputType::Boolean, 'label_override' => 'Alta definición', 'sort_order' => 0]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'sort_order' => 1]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true, 'sort_order' => 2]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'aspect', 'input_type' => InputType::String, 'source_schema' => ['type' => 'string', 'enum' => ['1:1', '16:9']], 'sort_order' => 3]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'n', 'input_type' => InputType::Integer, 'sort_order' => 4, 'source_schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'oculto', 'input_type' => InputType::String, 'visibility' => FieldVisibility::Hidden, 'sort_order' => 5]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'viejo', 'input_type' => InputType::String, 'stale' => true, 'sort_order' => 6]);

    $components = Schema::make()
        ->components(app(PipelineFormBuilder::class)->components($pipeline->refresh()))
        ->getComponents();
    $names = array_map(fn ($component): string => $component->getName(), $components);

    expect($names)->toBe(['inputs.describe_la_escena', 'inputs.hd', 'inputs.imagen', 'inputs.aspect', 'inputs.n'])
        ->and($components[0])->toBeInstanceOf(Textarea::class)
        ->and($components[0]->getRows())->toBe(4)
        ->and($components[0]->getLabel())->toBe('Qué quieres ver')
        ->and($components[1])->toBeInstanceOf(Toggle::class)
        ->and($components[1]->getLabel())->toBe('Alta definición')
        ->and($components[2])->toBeInstanceOf(FileUpload::class)
        ->and($components[3])->toBeInstanceOf(Select::class)
        ->and($components[4])->toBeInstanceOf(TextInput::class);

    $rules = app(PipelineFormBuilder::class)->rules($pipeline);

    expect($rules['inputs.describe_la_escena'])->toContain('required')
        ->and($rules['inputs.n'])->toContain('integer', 'min:1', 'max:4')
        ->and($rules['inputs.aspect'])->not->toContain('string');
});

it('validates JSON-encoded string, numeric, and boolean enum option keys', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'quoted',
        'input_type' => InputType::String,
        'source_schema' => ['type' => 'string', 'enum' => ['a "quoted", value']],
    ]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'numeric',
        'input_type' => InputType::Number,
        'source_schema' => ['type' => 'number', 'enum' => [2.5]],
    ]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'boolean',
        'input_type' => InputType::Boolean,
        'source_schema' => ['type' => 'boolean', 'enum' => [true, false]],
    ]);

    $builder = app(PipelineFormBuilder::class);
    $components = Schema::make()->components($builder->components($pipeline))->getComponents();
    $quotedSelect = collect($components)->first(fn ($component): bool => $component->getName() === 'inputs.quoted');

    expect($quotedSelect)->toBeInstanceOf(Select::class)
        ->and($quotedSelect->getOptions())->toBe(['"a \\u0022quoted\\u0022, value"' => 'a "quoted", value']);
    expect(json_decode(array_key_first($quotedSelect->getOptions()), true, flags: JSON_THROW_ON_ERROR))->toBe('a "quoted", value');

    $valid = Validator::make([
        'inputs' => [
            'quoted' => '"a \\u0022quoted\\u0022, value"',
            'numeric' => '2.5',
            'boolean' => 'false',
        ],
    ], $builder->rules($pipeline));
    $invalid = Validator::make([
        'inputs' => [
            'quoted' => '"other"',
            'numeric' => '"2.5"',
            'boolean' => '"true"',
        ],
    ], $builder->rules($pipeline));

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue()
        ->and($invalid->errors()->keys())->toBe(['inputs.quoted', 'inputs.numeric', 'inputs.boolean']);
});

it('authorizes only temporary or owned image paths', function (): void {
    $user = User::factory()->create();
    $ownedUpload = InputUpload::factory()->for($user)->create(['storage_path' => 'uploads/owned.png']);
    $otherUpload = InputUpload::factory()->create(['storage_path' => 'uploads/other.png']);
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);

    $this->actingAs($user);
    $image = Schema::make()
        ->components(app(PipelineFormBuilder::class)->components($pipeline))
        ->getComponents()[0];

    expect($image)->toBeInstanceOf(FileUpload::class)
        ->and($image->getDiskName())->toBe('inputs')
        ->and($image->getDirectory())->toBe('tmp')
        ->and($image->getVisibility())->toBe('private')
        ->and($image->getMaxSize())->toBe(config('media.max_upload_kb'))
        ->and($image->getAcceptedFileTypes())->toBe(config('media.allowed_mimes'))
        ->and($image->isFilePathAuthorized('tmp/upload.png'))->toBeTrue()
        ->and($image->isFilePathAuthorized($ownedUpload->storage_path))->toBeTrue()
        ->and($image->isFilePathAuthorized($otherUpload->storage_path))->toBeFalse()
        ->and($image->isFilePathAuthorized('tmp/../uploads/other.png'))->toBeFalse()
        ->and($image->isFilePathAuthorized('tmp/./upload.png'))->toBeFalse()
        ->and($image->isFilePathAuthorized('tmp/\\upload.png'))->toBeFalse()
        ->and($image->isFilePathAuthorized("tmp/\0upload.png"))->toBeFalse();
});

it('enforces source constraints for non-enum scalar inputs', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'titulo',
        'input_type' => InputType::String,
        'source_schema' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 4, 'pattern' => '^[A-Z]+$'],
    ]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'cantidad',
        'input_type' => InputType::Number,
        'source_schema' => ['type' => 'number', 'minimum' => 1, 'maximum' => 2],
    ]);

    $rules = app(PipelineFormBuilder::class)->rules($pipeline);
    $valid = Validator::make(['inputs' => ['titulo' => 'ABCD', 'cantidad' => 1.5]], $rules);
    $invalid = Validator::make(['inputs' => ['titulo' => 'ab', 'cantidad' => 3]], $rules);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue()
        ->and($invalid->errors()->keys())->toBe(['inputs.titulo', 'inputs.cantidad']);
});

it('rejects a visible unsupported field while ignoring inactive unsupported fields', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'oculto', 'input_type' => InputType::Unknown, 'visibility' => FieldVisibility::Hidden]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'viejo', 'input_type' => InputType::Unknown, 'stale' => true]);

    expect(app(PipelineFormBuilder::class)->components($pipeline))->toBe([]);

    PipelineField::factory()->for($pipeline)->create(['name' => 'visible', 'input_type' => InputType::Unknown]);

    expect(fn (): array => app(PipelineFormBuilder::class)->components($pipeline))->toThrow(LogicException::class, 'Pipeline not ready');
});
