<?php

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Generation\InputComposer;
use App\Services\Pipelines\PipelineActivation;
use App\Services\Pipelines\PipelineFieldConfiguration;
use App\Services\Pipelines\PipelineReadiness;
use Illuminate\Validation\ValidationException;

it('blocks activation and composition of unsupported root envelopes', function (array $root, string $keyword): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => $root + ['properties' => ['prompt' => ['type' => 'string']]]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt']);

    expect(implode(' ', app(PipelineReadiness::class)->evaluate($pipeline)))->toContain($keyword);
    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))->toThrow(ValidationException::class);
    expect(fn () => app(InputComposer::class)->compose($pipeline, ['prompt' => 'hola']))->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_ready)->toBeFalse();
})->with([
    'union' => [['type' => 'object', 'oneOf' => [['required' => ['prompt']]]], 'oneOf'],
    'reference' => [['$ref' => '#/other'], '$ref'],
    'array root' => [['type' => 'array'], 'type'],
    'schema additional properties' => [['additionalProperties' => ['type' => 'string']], 'additionalProperties'],
    'malformed required' => [['required' => 'prompt'], 'required'],
    'properties list' => [['properties' => [['type' => 'string']]], 'properties'],
    'malformed metadata' => [['title' => ['nested' => 'title']], 'title'],
]);

it('rejects integer transport before composing a bound source image', function (): void {
    $pipeline = pipelineWithProperties(['image' => ['type' => 'integer'], 'prompt' => ['type' => 'string']], PipelineKind::Editor);
    PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'source_schema' => ['type' => 'integer'], 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt]);
    $piece = Piece::factory()->create();

    expect(fn () => app(InputComposer::class)->compose($pipeline, [], $piece, 'ajusta la luz'))->toThrow(ValidationException::class);
});

it('rejects semantic and binding overrides incompatible with transport', function (string $transport, InputType $semantic, FieldRole $role): void {
    $pipeline = pipelineWithProperties(['value' => ['type' => $transport]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'value', 'source_schema' => ['type' => $transport], 'input_type' => $semantic, 'role' => $role]);

    expect(implode(' ', app(PipelineReadiness::class)->evaluate($pipeline)))->toContain('value');
    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))->toThrow(ValidationException::class);
    expect(fn () => app(InputComposer::class)->compose($pipeline, []))->toThrow(ValidationException::class);
})->with([
    ['integer', InputType::Image, FieldRole::Image],
    ['boolean', InputType::String, FieldRole::None],
    ['number', InputType::Integer, FieldRole::None],
    ['integer', InputType::Integer, FieldRole::Prompt],
    ['string', InputType::String, FieldRole::Image],
    ['string', InputType::Image, FieldRole::Prompt],
]);

it('deactivates a generator and clears its default when an admin hides its required prompt', function (): void {
    $pipeline = pipelineWithProperties(['prompt' => ['type' => 'string']]);
    $field = PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt, 'required' => true]);
    $activation = app(PipelineActivation::class);
    $activation->markReady($pipeline);
    $campaign = Campaign::factory()->create();
    attachPipeline($campaign, $pipeline);
    $activation->setDefault($campaign, $pipeline);

    app(PipelineFieldConfiguration::class)->save($pipeline, $field, ['visibility' => FieldVisibility::Hidden->value], User::factory()->artDirector()->create());

    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull()
        ->and(implode(' ', $pipeline->fresh()->readiness_errors))->toContain('prompt');
    expect(fn () => $activation->markReady($pipeline))->toThrow(ValidationException::class);
    expect(fn () => app(InputComposer::class)->compose($pipeline->fresh(), []))->toThrow(ValidationException::class);
});

it('preserves multiple string image overrides and no prompt on generators', function (): void {
    $pipeline = pipelineWithProperties(['a' => ['type' => 'string'], 'b' => ['type' => ['string', 'null'], 'x-krea-wire-type' => 'image']]);
    foreach ($pipeline->input_schema['properties'] as $name => $schema) {
        PipelineField::factory()->for($pipeline)->create(['name' => $name, 'source_schema' => $schema, 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
    }

    app(PipelineActivation::class)->markReady($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeTrue()
        ->and(app(InputComposer::class)->compose($pipeline, []))->toBe(['inputs' => [], 'uploadIds' => []]);
});

it('accepts hidden required injected editor and upscaler values', function (PipelineKind $kind, array $sizing, array $expected): void {
    $properties = ['image' => ['type' => 'string', 'x-krea-wire-type' => 'image']] + $sizing;
    if ($kind === PipelineKind::Editor) {
        $properties['prompt'] = ['type' => 'string', 'x-krea-wire-type' => 'text'];
    }
    $pipeline = pipelineWithProperties($properties, $kind);
    foreach ($properties as $name => $schema) {
        PipelineField::factory()->for($pipeline)->create([
            'name' => $name, 'source_schema' => $schema, 'required' => true, 'visibility' => FieldVisibility::Hidden,
            'input_type' => $name === 'image' ? InputType::Image : InputType::from($schema['type']),
            'role' => match ($name) {
                'image' => FieldRole::Image, 'prompt' => FieldRole::Prompt, default => FieldRole::None
            },
        ]);
    }
    $piece = Piece::factory()->create(['width' => 1920, 'height' => 1080]);

    app(PipelineActivation::class)->markReady($pipeline);

    expect(app(InputComposer::class)->compose($pipeline, [], $piece, 'ajusta la luz')['inputs'])
        ->toBe(['image' => ['__piece' => $piece->id]] + $expected);
})->with([
    'editor' => [PipelineKind::Editor, [], ['prompt' => 'ajusta la luz']],
    'dimensions' => [PipelineKind::Upscaler, ['width' => ['type' => 'integer'], 'height' => ['type' => 'integer']], ['width' => 3840, 'height' => 2160]],
    'scale' => [PipelineKind::Upscaler, ['scale' => ['type' => 'number']], ['scale' => 2.0]],
]);

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

it('accepts a fixed image upload linked to the pipeline from the catalog', function (): void {
    $pipeline = pipelineWithProperties(['referencia' => ['type' => 'string', 'format' => 'uri']]);
    $upload = InputUpload::factory()->catalog()->create();
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

it('rejects unlinked and malformed fixed image upload references', function (): void {
    $pipeline = pipelineWithProperties(['referencia' => ['type' => 'string', 'format' => 'uri']]);
    $sameBrandUnlinked = InputUpload::factory()->catalog()->create();
    $foreignLinked = InputUpload::factory()->create();
    $pipeline->inputUploads()->attach($foreignLinked);
    $references = [
        ['__upload' => $sameBrandUnlinked->id],
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
