<?php

namespace App\Services\Pipelines;

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\PipelineField;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use LogicException;

final class PipelineFormBuilder
{
    /**
     * @return list<Component>
     */
    public function components(Pipeline $pipeline): array
    {
        return $this->visibleFields($pipeline)
            ->map(fn (PipelineField $field): Component => $this->component($field))
            ->all();
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(Pipeline $pipeline): array
    {
        return $this->visibleFields($pipeline)
            ->mapWithKeys(function (PipelineField $field): array {
                $rules = [$field->required ? 'required' : 'nullable'];
                $schema = is_array($field->source_schema) ? $field->source_schema : [];

                if ($this->isEnum($schema)) {
                    $rules[] = Rule::in($this->encodedEnumValues($schema));

                    return ["inputs.{$field->name}" => $rules];
                }

                $rules = match ($field->input_type) {
                    InputType::Integer => [...$rules, 'integer'],
                    InputType::Number => [...$rules, 'numeric'],
                    InputType::Boolean => [...$rules, 'boolean'],
                    InputType::String => [...$rules, 'string'],
                    InputType::Image => $rules,
                    InputType::Unknown => throw new LogicException('Pipeline not ready'),
                };

                if (in_array($field->input_type, [InputType::Integer, InputType::Number], true)) {
                    $this->appendNumericRules($rules, $schema);
                }

                if ($field->input_type === InputType::String) {
                    $this->appendStringRules($rules, $schema);
                }

                return ["inputs.{$field->name}" => $rules];
            })
            ->all();
    }

    public function label(PipelineField $field): string
    {
        if ($field->role === FieldRole::Prompt && $field->label_override === null) {
            return 'Qué quieres ver';
        }

        return $field->label_override ?? Str::headline(str_replace('_', ' ', $field->name));
    }

    private function component(PipelineField $field): Component
    {
        $name = "inputs.{$field->name}";

        if ($this->isEnum($field->source_schema ?? [])) {
            return Select::make($name)
                ->label($this->label($field))
                ->options($this->enumOptions($field->source_schema));
        }

        return match ($field->input_type) {
            InputType::String => Textarea::make($name)
                ->label($this->label($field))
                ->rows($field->role === FieldRole::Prompt ? 4 : 2),
            InputType::Image => FileUpload::make($name)
                ->label($this->label($field))
                ->image()
                ->acceptedFileTypes(config('media.allowed_mimes'))
                ->disk('inputs')
                ->directory('tmp')
                ->visibility('private')
                ->maxSize(config('media.max_upload_kb'))
                ->preventFilePathTampering(
                    allowFilePathUsing: fn (string $file): bool => $this->isCanonicalTemporaryPath($file)
                        || InputUpload::query()
                            ->where('storage_path', $file)
                            ->where('user_id', auth()->id())
                            ->exists(),
                ),
            InputType::Integer => TextInput::make($name)
                ->label($this->label($field))
                ->numeric()
                ->integer(),
            InputType::Number => TextInput::make($name)
                ->label($this->label($field))
                ->numeric(),
            InputType::Boolean => Toggle::make($name)->label($this->label($field)),
            InputType::Unknown => throw new LogicException('Pipeline not ready'),
        };
    }

    /**
     * @return Collection<int, PipelineField>
     */
    private function visibleFields(Pipeline $pipeline): Collection
    {
        return $pipeline->fields()
            ->reorder()
            ->where('visibility', FieldVisibility::Visible->value)
            ->where('stale', false)
            ->orderByRaw('case when role = ? then 0 else 1 end', [FieldRole::Prompt->value])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function isCanonicalTemporaryPath(string $file): bool
    {
        if (! str_starts_with($file, 'tmp/')
            || str_contains($file, '\\')
            || preg_match('/\\p{C}/u', $file) !== 0) {
            return false;
        }

        $segments = explode('/', $file);
        if (count($segments) < 2) {
            return false;
        }

        foreach (array_slice($segments, 1) as $segment) {
            if ($segment === '' || in_array($segment, ['.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function isEnum(array $schema): bool
    {
        return isset($schema['enum']) && is_array($schema['enum']);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    private function enumOptions(array $schema): array
    {
        $options = [];

        foreach ($schema['enum'] as $value) {
            $options[json_encode($value, JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)] = match (true) {
                $value === null => 'null',
                $value === true => 'true',
                $value === false => 'false',
                default => (string) $value,
            };
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function encodedEnumValues(array $schema): array
    {
        return array_map(
            fn (mixed $value): string => json_encode($value, JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $schema['enum'],
        );
    }

    /**
     * @param  list<string|In>  $rules
     * @param  array<string, mixed>  $schema
     */
    private function appendNumericRules(array &$rules, array $schema): void
    {
        if (array_key_exists('minimum', $schema)) {
            $rules[] = "min:{$schema['minimum']}";
        }

        if (array_key_exists('maximum', $schema)) {
            $rules[] = "max:{$schema['maximum']}";
        }
    }

    /**
     * @param  list<string|In>  $rules
     * @param  array<string, mixed>  $schema
     */
    private function appendStringRules(array &$rules, array $schema): void
    {
        if (array_key_exists('minLength', $schema)) {
            $rules[] = "min:{$schema['minLength']}";
        }

        if (array_key_exists('maxLength', $schema)) {
            $rules[] = "max:{$schema['maxLength']}";
        }

        if (array_key_exists('pattern', $schema)) {
            $rules[] = 'regex:#'.str_replace('#', '\\#', $schema['pattern']).'#u';
        }
    }
}
