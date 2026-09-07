<?php

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Services\Pipelines\PipelineReadiness;

function pipelineWithProperties(array $properties, PipelineKind $kind = PipelineKind::Generator): Pipeline
{
    return Pipeline::factory()->create([
        'kind' => $kind,
        'input_schema' => ['properties' => $properties],
    ]);
}

it('requires a published input properties schema', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => ['type' => 'object']]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline))
        ->toBe(['El flujo no publica un esquema de entradas.']);
});

it('flags unknown fields and source schemas that remain unsupported', function (): void {
    $pipeline = pipelineWithProperties(['unknown' => [], 'custom' => ['type' => 'string', 'x-custom' => 1]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'unknown', 'input_type' => InputType::Unknown, 'source_schema' => []]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'custom', 'input_type' => InputType::String, 'source_schema' => ['type' => 'string', 'x-custom' => 1]]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo unknown: tipo no soportado.', 'Campo custom: tipo no soportado.');
});

it('requires new or changed fields to be configured', function (): void {
    $pipeline = pipelineWithProperties(['estilo' => ['type' => 'string']]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'estilo', 'needs_configuration' => true]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo estilo: nuevo o modificado; revisa su configuración.');
});

it('flags hidden required fields without a fixed value', function (): void {
    $pipeline = pipelineWithProperties(['n' => ['type' => 'integer']]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'n',
        'input_type' => InputType::Integer,
        'required' => true,
        'visibility' => FieldVisibility::Hidden,
        'source_schema' => ['type' => 'integer'],
    ]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo n: es obligatorio y no tiene valor fijo.');
});

it('validates typed fixed values including zero and scalar constraints', function (): void {
    $pipeline = pipelineWithProperties(['n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]]);
    $field = PipelineField::factory()->for($pipeline)->create([
        'name' => 'n',
        'input_type' => InputType::Integer,
        'required' => true,
        'visibility' => FieldVisibility::Hidden,
        'has_fixed_value' => true,
        'fixed_value' => 9,
        'source_schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
    ]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo n: el valor fijo no es válido.');

    $field->update(['fixed_value' => 0]);
    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo n: el valor fijo no es válido.');

    $field->update(['fixed_value' => 2]);
    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))->toBe([]);
});

it('validates nullable enums string lengths and patterns without coercion', function (): void {
    $field = PipelineField::factory()->create(['source_schema' => [
        'type' => ['string', 'null'],
        'enum' => ['uno', 'dos', null],
        'minLength' => 3,
        'maxLength' => 3,
        'pattern' => '[a-z]+',
    ]]);
    $readiness = app(PipelineReadiness::class);

    expect($readiness->validateValue($field, null))->toBeTrue()
        ->and($readiness->validateValue($field, 'dos'))->toBeTrue()
        ->and($readiness->validateValue($field, '2'))->toBeFalse()
        ->and($readiness->validateValue($field, 'DÓS'))->toBeFalse()
        ->and($readiness->validateValue($field, 2))->toBeFalse();
});

it('rejects malformed constraints in fixed value schemas', function (): void {
    $invalidPattern = PipelineField::factory()->create(['source_schema' => ['type' => 'string', 'pattern' => '[']]);
    $invalidMinimum = PipelineField::factory()->create(['source_schema' => ['type' => 'string', 'minimum' => 'one']]);

    expect(app(PipelineReadiness::class)->validateValue($invalidPattern, 'text'))->toBeFalse()
        ->and(app(PipelineReadiness::class)->validateValue($invalidMinimum, 'text'))->toBeFalse();
});

it('reports malformed scalar enums without throwing while evaluating fixed values', function (): void {
    $pipeline = pipelineWithProperties(['estilo' => ['type' => 'string']]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'estilo',
        'has_fixed_value' => true,
        'fixed_value' => 'editorial',
        'source_schema' => ['type' => 'string', 'enum' => 'not-an-array'],
    ]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo estilo: tipo no soportado.', 'Campo estilo: el valor fijo no es válido.');
});

it('rejects fixed values on bound fields', function (): void {
    $pipeline = pipelineWithProperties(['prompt' => ['type' => 'string']]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'prompt',
        'role' => FieldRole::Prompt,
        'has_fixed_value' => true,
        'fixed_value' => 'hola',
    ]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo prompt: un campo vinculado no puede tener valor fijo.');
});

it('accepts a fixed image upload linked to the pipeline from its campaign brand', function (): void {
    $pipeline = pipelineWithProperties(['referencia' => ['type' => 'string', 'format' => 'uri']]);
    $upload = InputUpload::factory()->for($pipeline->campaign->brand)->create();
    $pipeline->inputUploads()->attach($upload);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'referencia',
        'input_type' => InputType::Image,
        'visibility' => FieldVisibility::Hidden,
        'has_fixed_value' => true,
        'fixed_value' => ['__upload' => $upload->id],
        'source_schema' => ['type' => 'string', 'format' => 'uri'],
    ]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))->toBe([]);
});

it('rejects unlinked, foreign, and malformed fixed image upload references', function (): void {
    $pipeline = pipelineWithProperties(['referencia' => ['type' => 'string', 'format' => 'uri']]);
    $sameBrandUnlinked = InputUpload::factory()->for($pipeline->campaign->brand)->create();
    $foreignLinked = InputUpload::factory()->create();
    $pipeline->inputUploads()->attach($foreignLinked);
    $references = [
        ['__upload' => $sameBrandUnlinked->id],
        ['__upload' => $foreignLinked->id],
        ['__upload' => 999999],
        ['__upload' => 'not-an-id'],
        ['__upload' => $sameBrandUnlinked->id, 'extra' => true],
    ];

    foreach ($references as $index => $reference) {
        PipelineField::factory()->for($pipeline)->create([
            'name' => "referencia_{$index}",
            'input_type' => InputType::Image,
            'has_fixed_value' => true,
            'fixed_value' => $reference,
            'source_schema' => ['type' => 'string', 'format' => 'uri'],
        ]);
    }

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain(
            'Campo referencia_0: el valor fijo no es válido.',
            'Campo referencia_1: el valor fijo no es válido.',
            'Campo referencia_2: el valor fijo no es válido.',
            'Campo referencia_3: el valor fijo no es válido.',
            'Campo referencia_4: el valor fijo no es válido.',
        );
});

it('requires one image and one prompt binding for editors', function (): void {
    $pipeline = pipelineWithProperties(['foto' => ['type' => 'string'], 'prompt' => ['type' => 'string']], PipelineKind::Editor);
    PipelineField::factory()->for($pipeline)->create(['name' => 'foto', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Un editor necesita exactamente una imagen y un prompt vinculados.');

    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt, 'required' => true]);
    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))->toBe([]);
});

it('requires editor non-bound required fields to be hidden with a fixed value', function (): void {
    $pipeline = pipelineWithProperties(['foto' => ['type' => 'string'], 'prompt' => ['type' => 'string'], 'pasos' => ['type' => 'integer']], PipelineKind::Editor);
    PipelineField::factory()->for($pipeline)->create(['name' => 'foto', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'pasos', 'input_type' => InputType::Integer, 'required' => true]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo pasos: el editor no muestra campos; configura un valor fijo.');
});

it('requires one image and no prompt bindings for upscalers', function (): void {
    $pipeline = pipelineWithProperties(['foto' => ['type' => 'string'], 'prompt' => ['type' => 'string']], PipelineKind::Upscaler);
    PipelineField::factory()->for($pipeline)->create(['name' => 'foto', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Un upscaler necesita exactamente una imagen vinculada y ningún prompt.');
});

it('requires upscaler non-bound required fields to be hidden with a fixed value', function (): void {
    $pipeline = pipelineWithProperties(['foto' => ['type' => 'string'], 'factor' => ['type' => 'integer']], PipelineKind::Upscaler);
    PipelineField::factory()->for($pipeline)->create(['name' => 'foto', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'factor', 'input_type' => InputType::Integer, 'required' => true]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Campo factor: el editor no muestra campos; configura un valor fijo.');
});

it('allows at most one prompt binding for generators', function (): void {
    $pipeline = pipelineWithProperties(['prompt_a' => ['type' => 'string'], 'prompt_b' => ['type' => 'string']]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt_a', 'role' => FieldRole::Prompt]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt_b', 'role' => FieldRole::Prompt]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))
        ->toContain('Un generador solo puede tener un prompt.');
});

it('excludes stale fields from every readiness rule', function (): void {
    $pipeline = pipelineWithProperties(['old' => ['type' => 'string']]);
    PipelineField::factory()->for($pipeline)->create([
        'name' => 'old',
        'input_type' => InputType::Unknown,
        'needs_configuration' => true,
        'required' => true,
        'visibility' => FieldVisibility::Hidden,
        'stale' => true,
    ]);

    expect(app(PipelineReadiness::class)->evaluate($pipeline->refresh()))->toBe([]);
});
