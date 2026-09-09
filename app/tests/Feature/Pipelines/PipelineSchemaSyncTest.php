<?php

use App\Engines\KreaException;
use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Services\Pipelines\PipelineSchemaSync;

it('creates fields from a schema, proposes roles, and increments the current revision', function (): void {
    $schema = [
        'type' => 'object',
        'required' => ['describe_la_escena'],
        'properties' => [
            'describe_la_escena' => ['type' => 'string'],
            'estilo' => ['type' => 'string', 'enum' => ['a', 'b']],
        ],
    ];
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'ver-creador', 'config_revision' => 4]);
    $staleCopy = $pipeline->fresh();
    $pipeline->update(['config_revision' => 7]);
    fakeEngine()->withSchema('ver-creador', $schema, 'Creador');

    app(PipelineSchemaSync::class)->sync($staleCopy);

    $pipeline->refresh();
    expect($pipeline->config_revision)->toBe(8)
        ->and($pipeline->fields)->toHaveCount(2)
        ->and($pipeline->fields->firstWhere('name', 'describe_la_escena')->role)->toBe(FieldRole::Prompt)
        ->and($pipeline->fields->firstWhere('name', 'estilo')->input_type)->toBe(InputType::String)
        ->and($pipeline->schema_fetched_at)->not->toBeNull();
});

it('flags changed and newly required fields, then deactivates an invalid default pipeline', function (): void {
    $initialSchema = [
        'type' => 'object',
        'required' => ['describe_la_escena'],
        'properties' => ['describe_la_escena' => ['type' => 'string']],
    ];
    $updatedSchema = [
        'type' => 'object',
        'required' => ['describe_la_escena', 'marca'],
        'properties' => [
            'describe_la_escena' => ['type' => 'integer'],
            'marca' => ['type' => 'string'],
        ],
    ];
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'v']);
    $engine = fakeEngine()->withSchema('v', $initialSchema);
    $sync = app(PipelineSchemaSync::class);
    $sync->sync($pipeline);
    $pipeline->refresh()->update(['is_ready' => true, 'readiness_errors' => []]);
    $campaign = Campaign::factory()->create();
    attachPipeline($campaign, $pipeline);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $engine->withSchema('v', $updatedSchema);

    $sync->sync($pipeline->fresh());

    $pipeline->refresh();
    expect($pipeline->fields()->where('name', 'marca')->value('needs_configuration'))->toBeTrue()
        ->and($pipeline->fields()->where('name', 'describe_la_escena')->first())
        ->needs_configuration->toBeTrue()
        ->input_type->toBe(InputType::Integer)
        ->and($pipeline->is_ready)->toBeFalse()
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull()
        ->and(collect($pipeline->readiness_errors)->contains(fn (string $error): bool => str_contains($error, 'revisa su configuración')))->toBeTrue();
});

it('keeps field overrides, retains compatible input choices, and stales removed properties', function (): void {
    $initialSchema = [
        'type' => 'object',
        'required' => ['imagen_de_refe', 'modelo', 'tenis'],
        'properties' => [
            'imagen_de_refe' => ['type' => 'string', 'format' => 'uri'],
            'modelo' => ['type' => 'string', 'description' => 'Foto del modelo'],
            'tenis' => ['type' => 'string', 'format' => 'uri'],
        ],
    ];
    $updatedSchema = [
        'type' => 'object',
        'required' => ['modelo'],
        'properties' => [
            'modelo' => ['type' => 'string', 'format' => 'uri'],
            'nuevo' => ['type' => 'string'],
        ],
    ];
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'v']);
    $engine = fakeEngine()->withSchema('v', $initialSchema);
    $sync = app(PipelineSchemaSync::class);
    $sync->sync($pipeline);
    $pipeline->fields()->where('name', 'modelo')->update([
        'input_type' => InputType::String->value,
        'label_override' => 'Modelo elegido',
        'help_text' => 'Una referencia aprobada.',
        'visibility' => FieldVisibility::Hidden->value,
        'has_fixed_value' => true,
        'fixed_value' => json_encode('editorial'),
        'role' => FieldRole::None->value,
    ]);
    $pipeline->fields()->where('name', 'tenis')->update(['label_override' => 'Zapatilla']);
    $engine->withSchema('v', $updatedSchema);

    $sync->sync($pipeline->fresh());

    $pipeline->refresh();
    $modelo = $pipeline->fields()->where('name', 'modelo')->firstOrFail();
    expect($pipeline->fields()->where('name', 'imagen_de_refe')->value('stale'))->toBeTrue()
        ->and($pipeline->fields()->where('name', 'tenis')->firstOrFail())
        ->stale->toBeTrue()
        ->label_override->toBe('Zapatilla')
        ->and($modelo->input_type)->toBe(InputType::String)
        ->and($modelo->label_override)->toBe('Modelo elegido')
        ->and($modelo->help_text)->toBe('Una referencia aprobada.')
        ->and($modelo->visibility)->toBe(FieldVisibility::Hidden)
        ->and($modelo->has_fixed_value)->toBeTrue()
        ->and($modelo->fixed_value)->toBe('editorial')
        ->and($modelo->role)->toBe(FieldRole::None)
        ->and($pipeline->fields()->where('name', 'nuevo')->exists())->toBeTrue()
        ->and(collect($pipeline->readiness_errors)->contains(fn (string $error): bool => str_contains($error, 'imagen_de_refe')))->toBeFalse();

    $engine->withSchema('v', $initialSchema);
    $sync->sync($pipeline->fresh());

    expect($pipeline->fresh()->fields()->where('name', 'imagen_de_refe')->value('stale'))->toBeFalse()
        ->and($pipeline->fresh()->fields()->where('name', 'tenis')->value('stale'))->toBeFalse();
});

it('syncs the selected provider fixtures with their image and prompt suggestions', function (): void {
    $generatorFixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/krea/schema-generator.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $editorFixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/krea/schema-editor.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $generator = Pipeline::factory()->create(['provider_ref' => $generatorFixture['node_app_version_id']]);
    $editor = Pipeline::factory()->create([
        'kind' => PipelineKind::Editor,
        'provider_ref' => $editorFixture['node_app_version_id'],
    ]);
    fakeEngine()
        ->withSchema($generatorFixture['node_app_version_id'], $generatorFixture['input_openapi_schema'], $generatorFixture['name'])
        ->withSchema($editorFixture['node_app_version_id'], $editorFixture['input_openapi_schema'], $editorFixture['name']);
    $sync = app(PipelineSchemaSync::class);

    $sync->sync($generator);
    $sync->sync($editor);

    expect($generator->fresh()->fields()->firstWhere('name', 'describe_la_escena')->role)->toBe(FieldRole::Prompt)
        ->and($editor->fresh()->fields()->firstWhere('name', 'foto_para_editar')->input_type)->toBe(InputType::Image)
        ->and($editor->fresh()->fields()->firstWhere('name', 'foto_para_editar')->role)->toBe(FieldRole::Image)
        ->and($editor->fresh()->fields()->firstWhere('name', 'quiero_editar')->role)->toBe(FieldRole::Prompt)
        ->and($editor->fresh()->readiness_errors)->toBe([]);
});

it('stores a null provider schema as an explicit readiness error', function (): void {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'null-schema']);
    fakeEngine()->withSchema('null-schema', null);

    app(PipelineSchemaSync::class)->sync($pipeline);

    expect($pipeline->fresh()->input_schema)->toBeNull()
        ->and($pipeline->fresh()->readiness_errors)->toBe(['El flujo no publica un esquema de entradas.']);
});

it('flags a newly required field after a previously synced empty schema', function (): void {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'empty-then-required']);
    $engine = fakeEngine()->withSchema('empty-then-required', [
        'type' => 'object',
        'properties' => [],
    ]);
    $sync = app(PipelineSchemaSync::class);
    $sync->sync($pipeline);
    $engine->withSchema('empty-then-required', [
        'type' => 'object',
        'required' => ['marca'],
        'properties' => ['marca' => ['type' => 'string']],
    ]);

    $sync->sync($pipeline->fresh());

    expect($pipeline->fresh()->fields()->where('name', 'marca')->value('needs_configuration'))->toBeTrue()
        ->and($pipeline->fresh()->readiness_errors)->toContain('Campo marca: nuevo o modificado; revisa su configuración.');
});

it('marks malformed property definitions unsupported without failing the schema sync', function (): void {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'malformed-property']);
    fakeEngine()->withSchema('malformed-property', [
        'type' => 'object',
        'properties' => ['bad' => true],
    ]);

    app(PipelineSchemaSync::class)->sync($pipeline);

    $field = $pipeline->fresh()->fields()->firstOrFail();
    expect($field->input_type)->toBe(InputType::Unknown)
        ->and($field->source_schema)->toBe(['x-krea-invalid-property' => true])
        ->and($pipeline->fresh()->readiness_errors)->toContain('Campo bad: tipo no soportado.');
});

it('does not apply a fetched schema when the provider reference changes during its fetch', function (): void {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'original']);
    fakeEngine()
        ->withSchema('original', [
            'type' => 'object',
            'properties' => ['prompt' => ['type' => 'string']],
        ])
        ->duringDescribe(function () use ($pipeline): void {
            $pipeline->update(['provider_ref' => 'replacement']);
        });

    expect(fn (): Pipeline => app(PipelineSchemaSync::class)->sync($pipeline))
        ->toThrow(KreaException::class);

    expect($pipeline->fresh()->provider_ref)->toBe('replacement')
        ->and($pipeline->fresh()->input_schema)->toBeNull()
        ->and($pipeline->fresh()->config_revision)->toBe(1)
        ->and($pipeline->fresh()->fields)->toHaveCount(0);
});

it('leaves the pipeline and its fields untouched when fetching fails', function (): void {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'missing', 'config_revision' => 1]);
    $field = PipelineField::factory()->for($pipeline)->create(['name' => 'existing']);
    fakeEngine();

    expect(fn (): Pipeline => app(PipelineSchemaSync::class)->sync($pipeline))
        ->toThrow(KreaException::class);

    expect($pipeline->fresh()->config_revision)->toBe(1)
        ->and($pipeline->fresh()->input_schema)->toBeNull()
        ->and($field->fresh()->stale)->toBeFalse();
});

it('syncs with the studio key and never touches the brand key', function (): void {
    $brand = Brand::factory()->create(['krea_api_key' => 'brand-key']);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = attachPipeline($campaign, Pipeline::factory()->ready()->create());
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['prompt' => ['type' => 'string']]]);

    $synced = app(PipelineSchemaSync::class)->sync($pipeline);

    expect($synced->fields()->count())->toBe(1)->and($synced->is_ready)->toBeTrue();
});

it('drops readiness in every campaign when a resync introduces errors', function (): void {
    $first = Campaign::factory()->create();
    $second = Campaign::factory()->create();
    $pipeline = readyGenerator($first);
    attachPipeline($second, $pipeline);
    $first->update(['default_pipeline_id' => $pipeline->id]);
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['new' => ['type' => 'object']]]);

    app(PipelineSchemaSync::class)->sync($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($first->fresh()->default_pipeline_id)->toBeNull()
        ->and($first->activeGenerators()->count())->toBe(0)
        ->and($second->activeGenerators()->count())->toBe(0);
});
