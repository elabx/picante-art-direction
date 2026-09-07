<?php

use App\Enums\InputType;
use App\Services\Pipelines\SchemaSubset;

it('classifies primitives and suggests image inputs', function (array $schema, string $name, InputType $type): void {
    expect(SchemaSubset::classify($schema, $name))
        ->input_type->toBe($type)
        ->errors->toBe([]);
})->with([
    [['type' => 'string'], 'describe_la_escena', InputType::String],
    [['type' => ['string', 'null'], 'maxLength' => 200], 'nota', InputType::String],
    [['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'cantidad', InputType::Integer],
    [['type' => 'number'], 'escala', InputType::Number],
    [['type' => 'boolean', 'default' => false], 'hd', InputType::Boolean],
    [['type' => 'string', 'enum' => ['1:1', '16:9']], 'aspect', InputType::String],
    [['type' => 'string', 'format' => 'uri'], 'referencia', InputType::Image],
    [['type' => 'string', 'description' => 'Imagen de referencia'], 'referencia', InputType::Image],
    [['type' => 'string', 'x-krea-wire-type' => 'image'], 'entrada', InputType::Image],
    [['type' => 'string', 'x-krea-wire-type' => 'text'], 'entrada', InputType::String],
]);

it('reports every unsupported schema concern', function (array $schema, string $name, int $errors): void {
    $result = SchemaSubset::classify($schema, $name);

    expect($result['input_type'])->toBe(InputType::Unknown)
        ->and($result['errors'])->toHaveCount($errors);
})->with([
    [['type' => 'array', 'items' => ['type' => 'string']], 'tags', 2],
    [['$ref' => '#/x'], 'ref', 2],
    [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], 'u', 2],
    [['type' => 'string', 'x-custom' => 1], 'weird', 1],
]);

it('rejects malformed nullable and enum schemas', function (): void {
    expect(SchemaSubset::classify(['type' => ['string', 'integer']], 'union')['errors'])
        ->toContain('Tipo no soportado: union')
        ->and(SchemaSubset::classify(['type' => ['string', null]], 'nullable')['errors'])
        ->toContain('Tipo no soportado: union')
        ->and(SchemaSubset::classify(['type' => 'string', 'enum' => [['bad']]], 'enum')['errors'])
        ->toContain('Tipo no soportado: enum no escalar');
});

it('keeps compatible image and string overrides', function (): void {
    expect(SchemaSubset::isCompatible(InputType::Image, InputType::String))->toBeTrue()
        ->and(SchemaSubset::isCompatible(InputType::String, InputType::Image))->toBeTrue()
        ->and(SchemaSubset::isCompatible(InputType::Boolean, InputType::String))->toBeFalse();
});
