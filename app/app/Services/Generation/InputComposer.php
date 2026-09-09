<?php

namespace App\Services\Generation;

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Brand;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Media\InputUploadService;
use App\Services\Pipelines\PipelineReadiness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;

final class InputComposer
{
    public function __construct(
        private readonly InputUploadService $inputUploads,
        private readonly PipelineReadiness $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $visibleInputs
     * @return array{inputs: array<string, mixed>, uploadIds: list<int>}
     */
    public function compose(
        Pipeline $pipeline,
        array $visibleInputs,
        ?Piece $sourcePiece = null,
        ?string $instruction = null,
        ?User $user = null,
        ?Brand $brand = null,
    ): array {
        /** @var Collection<int, PipelineField> $fields */
        $fields = $pipeline->fields()->where('stale', false)->get();
        $visibleFields = $fields->filter(fn (PipelineField $field): bool => $field->visibility === FieldVisibility::Visible);

        $this->rejectExtraInputs($visibleInputs, $visibleFields);

        $configurationErrors = $this->readiness->evaluate($pipeline);
        if ($configurationErrors !== []) {
            throw ValidationException::withMessages(['pipeline' => $configurationErrors]);
        }

        if (in_array($pipeline->kind, [PipelineKind::Editor, PipelineKind::Upscaler], true) && $sourcePiece === null) {
            $this->invalid('source_piece', 'La pieza de origen es obligatoria.');
        }

        if ($pipeline->kind === PipelineKind::Editor && ($instruction === null || $instruction === '')) {
            $this->invalid('instruction', 'La instrucción es obligatoria.');
        }

        $sizingValues = $this->upscalerSizingValues($pipeline, $fields, $sourcePiece);
        $inputs = [];
        $uploadIds = [];

        foreach ($fields as $field) {
            if (array_key_exists($field->name, $sizingValues)) {
                $inputs[$field->name] = $this->validatedScalar($field, $sizingValues[$field->name]);

                continue;
            }

            if ($this->usesSourcePiece($pipeline, $field)) {
                $inputs[$field->name] = ['__piece' => $sourcePiece->id];

                continue;
            }

            if ($this->usesInstruction($pipeline, $field)) {
                $inputs[$field->name] = $this->validatedScalar($field, $instruction);

                continue;
            }

            if ($field->visibility === FieldVisibility::Visible) {
                if (! array_key_exists($field->name, $visibleInputs)) {
                    if ($field->required) {
                        $this->invalid($field->name, "El campo {$field->name} es obligatorio.");
                    }

                    continue;
                }

                $value = $visibleInputs[$field->name];
                if ($field->required && ($value === null || $value === '')) {
                    $this->invalid($field->name, "El campo {$field->name} es obligatorio.");
                }

                if (! $field->required && ($value === null || $value === '')) {
                    continue;
                }

                if ($field->input_type === InputType::Image) {
                    $uploadId = $this->visibleUploadId($field, $value);
                    $actor = $user ?? auth()->user();

                    if (! $actor instanceof User || $brand === null) {
                        throw new AuthorizationException;
                    }

                    $this->inputUploads->authorize($uploadId, $brand, $actor);
                    $inputs[$field->name] = ['__upload' => $uploadId];
                    $this->appendUploadId($uploadIds, $uploadId);

                    continue;
                }

                $inputs[$field->name] = $this->validatedScalar($field, $this->visibleScalar($field, $value));

                continue;
            }

            if ($field->has_fixed_value) {
                if ($field->input_type === InputType::Image) {
                    $uploadId = $this->fixedUploadId($field);
                    $this->inputUploads->authorizeForPipeline($uploadId, $pipeline);
                    $inputs[$field->name] = ['__upload' => $uploadId];
                    $this->appendUploadId($uploadIds, $uploadId);

                    continue;
                }

                $inputs[$field->name] = $this->validatedScalar($field, $field->fixed_value);
            }
        }

        return ['inputs' => $inputs, 'uploadIds' => $uploadIds];
    }

    /**
     * @param  array<string, mixed>  $visibleInputs
     * @param  Collection<int, PipelineField>  $visibleFields
     */
    private function rejectExtraInputs(array $visibleInputs, Collection $visibleFields): void
    {
        $allowed = $visibleFields->pluck('name')->all();
        $extra = array_values(array_diff(array_keys($visibleInputs), $allowed));

        if ($extra !== []) {
            $this->invalid('inputs', 'Campos no permitidos: '.implode(', ', $extra));
        }
    }

    /**
     * @param  Collection<int, PipelineField>  $fields
     * @return array<string, int|float>
     */
    private function upscalerSizingValues(Pipeline $pipeline, Collection $fields, ?Piece $sourcePiece): array
    {
        if ($pipeline->kind !== PipelineKind::Upscaler) {
            return [];
        }

        if ($sourcePiece === null || $sourcePiece->width < 1 || $sourcePiece->height < 1) {
            $this->invalid('source_piece', 'La pieza de origen debe tener dimensiones positivas.');
        }

        $names = $this->readiness->sizingFieldNames($pipeline->kind, $fields->all());
        if (count($names) === 2) {
            $target = FourKRule::target($sourcePiece->width, $sourcePiece->height);

            return [$names[0] => $target['width'], $names[1] => $target['height']];
        }

        if (count($names) === 1) {
            return [$names[0] => round(3840 / max($sourcePiece->width, $sourcePiece->height), 2)];
        }

        return [];
    }

    private function usesSourcePiece(Pipeline $pipeline, PipelineField $field): bool
    {
        return in_array($pipeline->kind, [PipelineKind::Editor, PipelineKind::Upscaler], true)
            && $field->role === FieldRole::Image;
    }

    private function usesInstruction(Pipeline $pipeline, PipelineField $field): bool
    {
        return $pipeline->kind === PipelineKind::Editor && $field->role === FieldRole::Prompt;
    }

    private function visibleUploadId(PipelineField $field, mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        $this->invalid($field->name, "La imagen {$field->name} no es válida.");
    }

    private function fixedUploadId(PipelineField $field): int
    {
        $value = $field->fixed_value;

        if (is_array($value)
            && array_keys($value) === ['__upload']
            && is_int($value['__upload'])
            && $value['__upload'] > 0) {
            return $value['__upload'];
        }

        $this->invalid($field->name, "La imagen fija {$field->name} no es válida.");
    }

    private function visibleScalar(PipelineField $field, mixed $value): mixed
    {
        if ($this->hasEnum($field)) {
            if (! is_string($value)) {
                $this->invalid($field->name, "El valor de {$field->name} no es válido.");
            }

            try {
                $decoded = json_decode($value, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->invalid($field->name, "El valor de {$field->name} no es válido.");
            }

            if (! is_scalar($decoded) && $decoded !== null) {
                $this->invalid($field->name, "El valor de {$field->name} no es válido.");
            }

            return $decoded;
        }

        return match ($field->input_type) {
            InputType::String => is_string($value) ? $value : $this->invalid($field->name, "El valor de {$field->name} no es válido."),
            InputType::Integer => $this->integerValue($field, $value),
            InputType::Number => $this->numberValue($field, $value),
            InputType::Boolean => $this->booleanValue($field, $value),
            default => $this->invalid($field->name, "El valor de {$field->name} no es válido."),
        };
    }

    private function integerValue(PipelineField $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[+-]?(?:0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer !== false) {
                return $integer;
            }
        }

        $this->invalid($field->name, "El valor de {$field->name} no es válido.");
    }

    private function numberValue(PipelineField $field, mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        if (is_string($value)
            && preg_match('/^[+-]?(?:(?:[0-9]+(?:\.[0-9]*)?)|(?:\.[0-9]+))(?:[eE][+-]?[0-9]+)?$/', $value) === 1) {
            $number = (float) $value;

            if (is_finite($number)) {
                return $number;
            }
        }

        $this->invalid($field->name, "El valor de {$field->name} no es válido.");
    }

    private function booleanValue(PipelineField $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        $this->invalid($field->name, "El valor de {$field->name} no es válido.");
    }

    private function validatedScalar(PipelineField $field, mixed $value): mixed
    {
        if ((! is_scalar($value) && $value !== null) || ! $this->readiness->validateValue($field, $value)) {
            $this->invalid($field->name, "El valor de {$field->name} no es válido.");
        }

        return $value;
    }

    private function hasEnum(PipelineField $field): bool
    {
        return is_array($field->source_schema) && array_key_exists('enum', $field->source_schema);
    }

    /**
     * @param  list<int>  $uploadIds
     */
    private function appendUploadId(array &$uploadIds, int $uploadId): void
    {
        if (! in_array($uploadId, $uploadIds, true)) {
            $uploadIds[] = $uploadId;
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
