<?php

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Generation\InputComposer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

it('rejects extra client keys before composing', function (): void {
    $pipeline = Pipeline::factory()->create(['kind' => PipelineKind::Generator, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);

    expect(fn (): array => app(InputComposer::class)->compose($pipeline->refresh(), ['describe_la_escena' => 'x', 'evil' => 'y']))
        ->toThrow(ValidationException::class, 'Campos no permitidos: evil');
});

it('composes visible fixed and bound inputs', function (): void {
    $pipeline = Pipeline::factory()->create(['kind' => PipelineKind::Editor, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'foto_para_editar', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'quiero_editar', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'fuerza', 'input_type' => InputType::Number, 'visibility' => FieldVisibility::Hidden, 'has_fixed_value' => true, 'fixed_value' => 0.5, 'source_schema' => ['type' => 'number']]);
    $piece = Piece::factory()->create();

    $result = app(InputComposer::class)->compose($pipeline->refresh(), [], $piece, 'quita la caja');

    expect($result['inputs'])->toBe([
        'foto_para_editar' => ['__piece' => $piece->id],
        'quiero_editar' => 'quita la caja',
        'fuerza' => 0.5,
    ]);
});

it('fills upscaler width and height from the 4K rule', function (): void {
    $pipeline = Pipeline::factory()->create(['kind' => PipelineKind::Upscaler, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'width', 'input_type' => InputType::Integer, 'visibility' => FieldVisibility::Hidden, 'source_schema' => ['type' => 'integer']]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'height', 'input_type' => InputType::Integer, 'visibility' => FieldVisibility::Hidden, 'source_schema' => ['type' => 'integer']]);
    $piece = Piece::factory()->create(['width' => 1920, 'height' => 1080]);

    expect(app(InputComposer::class)->compose($pipeline->refresh(), [], $piece)['inputs'])
        ->toMatchArray(['width' => 3840, 'height' => 2160]);
});

it('requires visible required fields and decodes enum JSON keys', function (): void {
    $pipeline = Pipeline::factory()->create(['kind' => PipelineKind::Generator, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'n', 'input_type' => InputType::Integer, 'source_schema' => ['type' => 'integer', 'enum' => [1, 2]]]);

    expect(fn (): array => app(InputComposer::class)->compose($pipeline->refresh(), []))->toThrow(ValidationException::class)
        ->and(app(InputComposer::class)->compose($pipeline, ['describe_la_escena' => 'x', 'n' => '2'])['inputs'])
        ->toBe(['describe_la_escena' => 'x', 'n' => 2]);
});

it('keeps configured field order while omitting absent optional visible inputs', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'primero', 'sort_order' => 1]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'fijo', 'sort_order' => 2, 'visibility' => FieldVisibility::Hidden, 'has_fixed_value' => true, 'fixed_value' => 'siempre']);
    PipelineField::factory()->for($pipeline)->create(['name' => 'opcional', 'sort_order' => 3]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'ultimo', 'sort_order' => 4]);

    expect(app(InputComposer::class)->compose($pipeline->refresh(), ['primero' => 'uno', 'ultimo' => 'dos'])['inputs'])
        ->toBe(['primero' => 'uno', 'fijo' => 'siempre', 'ultimo' => 'dos']);
});

it('omits blank optional form values while preserving false', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'texto', 'input_type' => InputType::String]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'numero', 'input_type' => InputType::Number, 'source_schema' => ['type' => 'number']]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'activo', 'input_type' => InputType::Boolean, 'source_schema' => ['type' => 'boolean']]);

    expect(app(InputComposer::class)->compose($pipeline->refresh(), ['texto' => null, 'numero' => '', 'activo' => false])['inputs'])
        ->toBe(['activo' => false]);
});

it('composes a configured fixed upload reference and returns its unique id', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $upload = InputUpload::factory()->create();
    $pipeline->inputUploads()->attach($upload);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'marca_de_agua',
        'input_type' => InputType::Image,
        'visibility' => FieldVisibility::Hidden,
        'has_fixed_value' => true,
        'fixed_value' => ['__upload' => $upload->id],
    ]);

    expect(app(InputComposer::class)->compose($pipeline->refresh(), []))
        ->toBe(['inputs' => ['marca_de_agua' => ['__upload' => $upload->id]], 'uploadIds' => [$upload->id]]);
});

it('uses a configured scale field without inventing unconfigured sizing inputs', function (): void {
    $pipeline = Pipeline::factory()->create(['kind' => PipelineKind::Upscaler, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'scale_factor', 'input_type' => InputType::Number, 'visibility' => FieldVisibility::Hidden, 'source_schema' => ['type' => 'number']]);
    $piece = Piece::factory()->create(['width' => 1000, 'height' => 667]);

    expect(app(InputComposer::class)->compose($pipeline->refresh(), [], $piece)['inputs'])
        ->toBe(['image' => ['__piece' => $piece->id], 'scale_factor' => 3.84]);
});

it('does not add sizing inputs that the upscaler schema does not declare', function (): void {
    $pipeline = Pipeline::factory()->create(['kind' => PipelineKind::Upscaler, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    $piece = Piece::factory()->create(['width' => 1920, 'height' => 1080]);

    expect(app(InputComposer::class)->compose($pipeline->refresh(), [], $piece)['inputs'])
        ->toBe(['image' => ['__piece' => $piece->id]]);
});

it('converts only representable scalar values and round-trips JSON enum keys', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'entero', 'input_type' => InputType::Integer, 'required' => true, 'source_schema' => ['type' => 'integer']]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'activo', 'input_type' => InputType::Boolean, 'required' => true, 'source_schema' => ['type' => 'boolean']]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'quoted', 'input_type' => InputType::String, 'required' => true, 'source_schema' => ['type' => 'string', 'enum' => ['a "quoted", value']]]);

    $composer = app(InputComposer::class);

    expect($composer->compose($pipeline->refresh(), [
        'entero' => '2',
        'activo' => 'false',
        'quoted' => '"a \\u0022quoted\\u0022, value"',
    ])['inputs'])->toBe([
        'entero' => 2,
        'activo' => false,
        'quoted' => 'a "quoted", value',
    ]);

    expect(fn (): array => $composer->compose($pipeline, ['entero' => '1.5', 'activo' => 'false', 'quoted' => '"a \\u0022quoted\\u0022, value"']))
        ->toThrow(ValidationException::class)
        ->and(fn (): array => $composer->compose($pipeline, ['entero' => '2', 'activo' => 'bogus', 'quoted' => '"a \\u0022quoted\\u0022, value"']))
        ->toThrow(ValidationException::class)
        ->and(fn (): array => $composer->compose($pipeline, ['entero' => '2', 'activo' => 'false', 'quoted' => '"other"']))
        ->toThrow(ValidationException::class);
});

it('authorizes visible uploads with the explicit actor', function (): void {
    $user = User::factory()->editor()->create();
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $upload = InputUpload::factory()->create([
        'brand_id' => $pipeline->campaign->brand_id,
        'user_id' => $user->id,
    ]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'referencia', 'input_type' => InputType::Image, 'required' => true]);

    $composer = app(InputComposer::class);

    expect($composer->compose($pipeline->refresh(), ['referencia' => $upload->id], null, null, $user))
        ->toMatchArray(['inputs' => ['referencia' => ['__upload' => $upload->id]], 'uploadIds' => [$upload->id]])
        ->and(fn (): array => $composer->compose($pipeline, ['referencia' => $upload->id]))
        ->toThrow(AuthorizationException::class);
});
