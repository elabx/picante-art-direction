<?php

namespace App\Services\Pipelines;

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Pipeline;
use App\Models\PipelineField;

final class PipelineReadiness
{
    /**
     * @return list<string>
     */
    public function evaluate(Pipeline $pipeline): array
    {
        if (! is_array($pipeline->input_schema) || ! array_key_exists('properties', $pipeline->input_schema) || ! is_array($pipeline->input_schema['properties'])) {
            return ['El flujo no publica un esquema de entradas.'];
        }

        $fields = $pipeline->activeFields()->get();
        $errors = SchemaSubset::rootErrors($pipeline->input_schema);
        $sizingFields = $this->sizingFieldNames($pipeline->kind, $fields->all());

        foreach ($fields as $field) {
            $this->appendFieldErrors($pipeline, $field, $errors, $sizingFields);
        }

        $this->appendKindErrors($pipeline->kind, $fields->all(), $errors, $sizingFields);

        return $errors;
    }

    public function validateValue(PipelineField $field, mixed $value): bool
    {
        $schema = $field->source_schema;
        if (! is_array($schema) || SchemaSubset::classify($schema, $field->name)['errors'] !== [] || ! $this->hasValidConstraints($schema)) {
            return false;
        }

        $nullable = ($schema['nullable'] ?? false) === true || $this->hasNullableType($schema['type'] ?? null);
        if ($value === null) {
            return $nullable;
        }

        $type = $this->primitiveType($schema['type'] ?? null);
        if ($type === null || ! $this->matchesType($type, $value)) {
            return false;
        }

        if (array_key_exists('enum', $schema) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }

        if (! $this->matchesNumberConstraints($schema, $value) || ! $this->matchesStringConstraints($schema, $value)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $errors
     */
    private function appendFieldErrors(Pipeline $pipeline, PipelineField $field, array &$errors, array $sizingFields): void
    {
        $classification = SchemaSubset::classify($field->source_schema ?? [], $field->name);
        if ($field->input_type === InputType::Unknown || $classification['errors'] !== []) {
            $errors[] = "Campo {$field->name}: tipo no soportado.";
        }

        if (! SchemaSubset::isCompatible($field->input_type, $classification['input_type'])) {
            $errors[] = "Campo {$field->name}: el tipo configurado es incompatible con el transporte.";
        }
        if (($field->role === FieldRole::Image && $field->input_type !== InputType::Image)
            || ($field->role === FieldRole::Prompt && $field->input_type !== InputType::String)) {
            $errors[] = "Campo {$field->name}: el vínculo es incompatible con el tipo configurado.";
        }

        if ($field->needs_configuration) {
            $errors[] = "Campo {$field->name}: nuevo o modificado; revisa su configuración.";
        }

        $injected = ($pipeline->kind === PipelineKind::Editor && $field->role === FieldRole::Prompt)
            || ($pipeline->kind !== PipelineKind::Generator && $field->role === FieldRole::Image)
            || in_array($field->name, $sizingFields, true);
        if ($field->required && $field->visibility === FieldVisibility::Hidden && ! $field->has_fixed_value && ! $injected) {
            $errors[] = "Campo {$field->name}: es obligatorio y no tiene valor fijo.";
        }

        if ($field->has_fixed_value && ! $this->hasValidFixedValue($pipeline, $field)) {
            $errors[] = "Campo {$field->name}: el valor fijo no es válido.";
        }

        if ($field->has_fixed_value && $field->role !== FieldRole::None) {
            $errors[] = "Campo {$field->name}: un campo vinculado no puede tener valor fijo.";
        }
    }

    private function hasValidFixedValue(Pipeline $pipeline, PipelineField $field): bool
    {
        if (! is_array($field->fixed_value)) {
            return $this->validateValue($field, $field->fixed_value);
        }

        return $this->isLinkedImageUploadReference($pipeline, $field, $field->fixed_value);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function isLinkedImageUploadReference(Pipeline $pipeline, PipelineField $field, array $value): bool
    {
        if ($field->input_type !== InputType::Image
            || array_keys($value) !== ['__upload']
            || ! is_int($value['__upload'])
            || $value['__upload'] < 1) {
            return false;
        }

        return $pipeline->inputUploads()
            ->whereKey($value['__upload'])
            ->exists();
    }

    /**
     * @param  list<PipelineField>  $fields
     * @param  list<string>  $errors
     */
    private function appendKindErrors(PipelineKind $kind, array $fields, array &$errors, array $sizingFields): void
    {
        $images = array_filter($fields, fn (PipelineField $field): bool => $field->role === FieldRole::Image);
        $prompts = array_filter($fields, fn (PipelineField $field): bool => $field->role === FieldRole::Prompt);

        if ($kind === PipelineKind::Editor) {
            if (count($images) !== 1 || count($prompts) !== 1) {
                $errors[] = 'Un editor necesita exactamente una imagen y un prompt vinculados.';
            }

            $this->appendHiddenFixedErrors($fields, $errors, $sizingFields);
        }

        if ($kind === PipelineKind::Upscaler) {
            if (count($images) !== 1 || count($prompts) !== 0) {
                $errors[] = 'Un upscaler necesita exactamente una imagen vinculada y ningún prompt.';
            }

            $this->appendHiddenFixedErrors($fields, $errors, $sizingFields);
        }

        if ($kind === PipelineKind::Generator && count($prompts) > 1) {
            $errors[] = 'Un generador solo puede tener un prompt.';
        }
    }

    /**
     * @param  list<PipelineField>  $fields
     * @param  list<string>  $errors
     */
    private function appendHiddenFixedErrors(array $fields, array &$errors, array $sizingFields): void
    {
        foreach ($fields as $field) {
            if ($field->required && $field->role === FieldRole::None && ! in_array($field->name, $sizingFields, true) && ($field->visibility !== FieldVisibility::Hidden || ! $field->has_fixed_value)) {
                $errors[] = "Campo {$field->name}: el editor no muestra campos; configura un valor fijo.";
            }
        }
    }

    /**
     * @param  list<PipelineField>  $fields
     * @return list<string>
     */
    public function sizingFieldNames(PipelineKind $kind, array $fields): array
    {
        if ($kind !== PipelineKind::Upscaler) {
            return [];
        }
        $byName = collect($fields)->keyBy('name');
        foreach ([['width', 'height'], ['target_width', 'target_height']] as [$width, $height]) {
            if ($byName->get($width)?->input_type === InputType::Integer && $byName->get($height)?->input_type === InputType::Integer) {
                return [$width, $height];
            }
        }
        $scaleFields = array_filter($fields, fn (PipelineField $field): bool => in_array($field->name, ['scale', 'scale_factor'], true) && $field->input_type === InputType::Number);

        return count($scaleFields) === 1 ? [array_values($scaleFields)[0]->name] : [];
    }

    private function hasNullableType(mixed $type): bool
    {
        return is_array($type) && count($type) === 2 && in_array('null', $type, true);
    }

    private function primitiveType(mixed $type): ?string
    {
        if (is_string($type)) {
            return $type;
        }

        if (is_array($type) && count($type) === 2) {
            $types = array_values(array_filter($type, fn (mixed $item): bool => $item !== 'null'));

            return count($types) === 1 && is_string($types[0]) ? $types[0] : null;
        }

        return null;
    }

    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            default => false,
        };
    }

    private function hasValidConstraints(array $schema): bool
    {
        if (array_key_exists('enum', $schema) && ! is_array($schema['enum'])) {
            return false;
        }

        if (array_key_exists('nullable', $schema) && ! is_bool($schema['nullable'])) {
            return false;
        }

        foreach (['minimum', 'maximum'] as $constraint) {
            if (array_key_exists($constraint, $schema) && (! is_int($schema[$constraint]) && ! is_float($schema[$constraint]))) {
                return false;
            }
        }

        foreach (['minLength', 'maxLength'] as $constraint) {
            if (array_key_exists($constraint, $schema) && (! is_int($schema[$constraint]) || $schema[$constraint] < 0)) {
                return false;
            }
        }

        if (! array_key_exists('pattern', $schema)) {
            return true;
        }

        return is_string($schema['pattern'])
            && @preg_match('#'.str_replace('#', '\\#', $schema['pattern']).'#u', '') !== false;
    }

    private function matchesNumberConstraints(array $schema, mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return true;
        }

        foreach (['minimum', 'maximum'] as $constraint) {
            if (array_key_exists($constraint, $schema) && (! is_int($schema[$constraint]) && ! is_float($schema[$constraint]))) {
                return false;
            }
        }

        return (! array_key_exists('minimum', $schema) || $value >= $schema['minimum'])
            && (! array_key_exists('maximum', $schema) || $value <= $schema['maximum']);
    }

    private function matchesStringConstraints(array $schema, mixed $value): bool
    {
        if (! is_string($value)) {
            return true;
        }

        foreach (['minLength', 'maxLength'] as $constraint) {
            if (array_key_exists($constraint, $schema) && (! is_int($schema[$constraint]) || $schema[$constraint] < 0)) {
                return false;
            }
        }

        $length = mb_strlen($value);
        if ((array_key_exists('minLength', $schema) && $length < $schema['minLength']) || (array_key_exists('maxLength', $schema) && $length > $schema['maxLength'])) {
            return false;
        }

        if (! array_key_exists('pattern', $schema)) {
            return true;
        }

        if (! is_string($schema['pattern'])) {
            return false;
        }

        return @preg_match('#'.str_replace('#', '\\#', $schema['pattern']).'#u', $value) === 1;
    }
}
