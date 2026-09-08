<?php

namespace App\Services\Pipelines;

use App\Enums\InputType;

final class SchemaSubset
{
    /** @return list<string> */
    public static function rootErrors(array $schema): array
    {
        $errors = [];
        foreach (array_keys($schema) as $key) {
            if (! in_array($key, ['type', 'properties', 'required', 'additionalProperties', 'title', 'description', '$schema'], true)) {
                $errors[] = "Esquema: palabra clave no soportada: {$key}.";
            }
        }
        if (array_key_exists('type', $schema) && $schema['type'] !== 'object') {
            $errors[] = 'Esquema: type debe ser object.';
        }
        foreach (['title', 'description', '$schema'] as $key) {
            if (array_key_exists($key, $schema) && ! is_string($schema[$key])) {
                $errors[] = "Esquema: {$key} debe ser texto.";
            }
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
            $errors[] = 'El flujo no publica un esquema de entradas.';
        } elseif ($properties !== [] && array_is_list($properties)) {
            $errors[] = 'Esquema: properties debe ser un objeto de propiedades.';
        } else {
            foreach ($properties as $name => $property) {
                if (! is_array($property)) {
                    $errors[] = "Campo {$name}: esquema no soportado.";

                    continue;
                }
                foreach (self::classify($property, (string) $name)['errors'] as $error) {
                    $errors[] = "Campo {$name}: {$error}.";
                }
            }
        }
        if (array_key_exists('required', $schema)
            && (! is_array($schema['required']) || ! array_is_list($schema['required'])
                || array_filter($schema['required'], fn (mixed $name): bool => ! is_string($name) || ! array_key_exists($name, $properties)) !== [])) {
            $errors[] = 'Esquema: required debe enumerar propiedades existentes.';
        }
        if (array_key_exists('additionalProperties', $schema) && ! is_bool($schema['additionalProperties'])) {
            $errors[] = 'Esquema: additionalProperties solo admite un booleano.';
        }

        return $errors;
    }

    private const SUPPORTED_KEYS = [
        'type',
        'enum',
        'default',
        'minLength',
        'maxLength',
        'pattern',
        'minimum',
        'maximum',
        'nullable',
        'description',
        'title',
        'format',
        'examples',
        'x-krea-wire-type',
    ];

    /**
     * @return array{input_type: InputType, errors: list<string>}
     */
    public static function classify(array $schema, string $name): array
    {
        $errors = [];

        foreach (array_keys($schema) as $key) {
            if (! in_array($key, self::SUPPORTED_KEYS, true)) {
                $errors[] = "Palabra clave no soportada: {$key}";
            }
        }

        $wireType = $schema['x-krea-wire-type'] ?? null;
        if (array_key_exists('x-krea-wire-type', $schema) && ! in_array($wireType, ['text', 'image'], true)) {
            $errors[] = 'Palabra clave no soportada: x-krea-wire-type';
        }

        $type = self::primitiveType($schema['type'] ?? null, $errors);

        if (array_key_exists('enum', $schema) && (! is_array($schema['enum']) || array_filter($schema['enum'], fn (mixed $value): bool => ! is_scalar($value) && $value !== null))) {
            $errors[] = 'Tipo no soportado: enum no escalar';
        }

        $errors = array_values(array_unique($errors));
        if ($errors !== []) {
            return ['input_type' => InputType::Unknown, 'errors' => $errors];
        }

        $looksLikeImage = $wireType === 'image'
            || in_array($schema['format'] ?? null, ['uri', 'binary'], true)
            || self::mentionsImage($name, $schema);

        return [
            'input_type' => match ($type) {
                'string' => $looksLikeImage ? InputType::Image : InputType::String,
                'integer' => InputType::Integer,
                'number' => InputType::Number,
                'boolean' => InputType::Boolean,
            },
            'errors' => [],
        ];
    }

    public static function isCompatible(InputType $existing, InputType $fresh): bool
    {
        return $existing === $fresh
            || ($existing === InputType::Image && $fresh === InputType::String)
            || ($existing === InputType::String && $fresh === InputType::Image);
    }

    /**
     * @param  list<string>  $errors
     */
    private static function primitiveType(mixed $value, array &$errors): string
    {
        if (is_string($value)) {
            if (in_array($value, ['string', 'integer', 'number', 'boolean'], true)) {
                return $value;
            }

            $errors[] = "Tipo no soportado: {$value}";

            return '';
        }

        if (is_array($value)) {
            $types = array_values($value);
            $nullableTypes = array_values(array_filter($types, fn (mixed $type): bool => $type === 'null'));
            $primitiveTypes = array_values(array_filter($types, fn (mixed $type): bool => $type !== 'null'));
            if (count($types) === 2 && count($nullableTypes) === 1 && count($primitiveTypes) === 1 && in_array($primitiveTypes[0], ['string', 'integer', 'number', 'boolean'], true)) {
                return $primitiveTypes[0];
            }

            $errors[] = 'Tipo no soportado: union';

            return '';
        }

        $errors[] = 'Tipo no soportado: desconocido';

        return '';
    }

    private static function mentionsImage(string $name, array $schema): bool
    {
        $description = is_string($schema['description'] ?? null) ? $schema['description'] : '';
        $title = is_string($schema['title'] ?? null) ? $schema['title'] : '';

        return preg_match('/imagen|image|foto|photo/i', "{$name} {$description} {$title}") === 1;
    }
}
