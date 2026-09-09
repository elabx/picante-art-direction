<?php

namespace App\Services\Pipelines;

use App\Engines\EngineResolver;
use App\Engines\KreaException;
use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Models\Pipeline;
use App\Models\PipelineField;
use Illuminate\Support\Facades\DB;

final class PipelineSchemaSync
{
    public function __construct(
        private readonly EngineResolver $engines,
        private readonly PipelineReadiness $readiness,
        private readonly PipelineActivation $activation,
    ) {}

    public function sync(Pipeline $pipeline): Pipeline
    {
        $providerRef = $pipeline->provider_ref;
        $schema = $this->engines->forStudio()->describe($providerRef);

        $locked = DB::transaction(function () use ($pipeline, $providerRef, $schema): Pipeline {
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());

            if ($current->provider_ref !== $providerRef) {
                throw new KreaException('La configuración del flujo cambió; vuelve a sincronizar.');
            }

            $isInitialSync = $current->schema_fetched_at === null && $current->input_schema === null;
            $inputSchema = $schema->inputSchema;
            $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
            $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];

            $current->forceFill([
                'input_schema' => $inputSchema,
                'schema_fetched_at' => now(),
                'config_revision' => $current->config_revision + 1,
            ])->save();

            $existingFields = $current->fields()->get()->keyBy('name');
            $seen = [];
            $sortOrder = 0;

            foreach ($properties as $name => $property) {
                $name = (string) $name;
                $property = is_array($property) ? $property : ['x-krea-invalid-property' => $property];
                $classification = SchemaSubset::classify($property, $name);
                $isRequired = in_array($name, $required, true);
                $field = $existingFields->get($name);
                $seen[] = $name;

                if ($field === null) {
                    $field = new PipelineField([
                        'pipeline_id' => $current->id,
                        'name' => $name,
                        'input_type' => $classification['input_type'],
                        'role' => $classification['input_type'] === InputType::Image ? FieldRole::Image : FieldRole::None,
                        'visibility' => FieldVisibility::Visible,
                        'needs_configuration' => ! $isInitialSync && $isRequired,
                    ]);
                } elseif (! SchemaSubset::isCompatible($field->input_type, $classification['input_type'])) {
                    $field->input_type = $classification['input_type'];
                    $field->needs_configuration = true;
                }

                $field->forceFill([
                    'source_schema' => $property,
                    'required' => $isRequired,
                    'stale' => false,
                    'sort_order' => $sortOrder++,
                ])->save();
            }

            if ($seen === []) {
                $current->fields()->update(['stale' => true]);
            } else {
                $current->fields()->whereNotIn('name', $seen)->update(['stale' => true]);
            }

            if ($isInitialSync) {
                $current->fields()
                    ->where('stale', false)
                    ->where('input_type', InputType::String->value)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first()?->update(['role' => FieldRole::Prompt]);
            }

            $errors = $this->readiness->evaluate($current->refresh());
            if ($errors !== []) {
                $this->activation->markNotReady($current, $errors);
            } else {
                $current->forceFill(['readiness_errors' => [], 'is_ready' => true])->save();
            }

            return $current;
        }, attempts: 3);

        return $locked->refresh();
    }
}
