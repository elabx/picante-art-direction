<?php

namespace App\Services\Pipelines;

use App\Enums\InputType;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Media\InputUploadService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PipelineFieldConfiguration
{
    public const EDITABLE = ['input_type', 'label_override', 'help_text', 'visibility', 'has_fixed_value', 'fixed_value', 'role'];

    public function __construct(
        private readonly InputUploadService $uploads,
        private readonly PipelineReadiness $readiness,
        private readonly PipelineActivation $activation,
    ) {}

    public function formData(PipelineField $field): array
    {
        $data = Arr::only($field->attributesToArray(), self::EDITABLE);
        $data['fixed_value'] = $field->input_type === InputType::Image
            ? $this->existingImagePath($field)
            : json_encode($field->fixed_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $data;
    }

    public function existingImagePath(PipelineField $field): ?string
    {
        if (! $field->has_fixed_value || ! is_array($field->fixed_value) || ! is_int($field->fixed_value['__upload'] ?? null)) {
            return null;
        }
        $upload = $this->uploads->authorizeForPipeline($field->fixed_value['__upload'], $field->pipeline);
        abort_unless($upload->brand_id === $field->pipeline->campaign->brand_id, 403);

        return $upload->storage_path;
    }

    /** @param array<string, mixed> $data */
    public function save(Pipeline $pipeline, PipelineField $field, array $data, User $user): PipelineField
    {
        Gate::forUser($user)->authorize('update', $field);
        $original = PipelineField::query()->where('pipeline_id', $pipeline->id)->findOrFail($field->id);
        $pipeline = $pipeline->fresh();
        $campaignId = $pipeline->campaign_id;
        $brandId = $pipeline->campaign->brand_id;
        $data = Arr::only($data, self::EDITABLE);
        $hasFixedValue = (bool) ($data['has_fixed_value'] ?? $original->has_fixed_value);
        $type = $data['input_type'] ?? $original->input_type->value;
        $value = $data['fixed_value'] ?? null;
        $upload = null;

        if ($hasFixedValue && $type === InputType::Image->value) {
            $path = is_string($value) ? $value : null;
            if ($path !== null && $path === $this->existingImagePath($original)) {
                $value = $original->fixed_value;
            } elseif ($path !== null && str_starts_with($path, 'tmp/')) {
                $upload = $this->uploads->finalize($path, $pipeline->campaign->brand, $user);
                $value = ['__upload' => $upload->id];
            } else {
                throw ValidationException::withMessages(['fixed_value' => 'Selecciona una imagen válida de este flujo.']);
            }
        } elseif ($hasFixedValue && is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return DB::transaction(function () use ($pipeline, $original, $campaignId, $brandId, $data, $hasFixedValue, $value, $upload): PipelineField {
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->id);
            $field = PipelineField::query()->where('pipeline_id', $current->id)->lockForUpdate()->findOrFail($original->id);
            if ($campaign->brand_id !== $brandId || $current->campaign_id !== $campaignId || $field->getAttributes() !== $original->getAttributes()) {
                throw ValidationException::withMessages(['fixed_value' => 'La configuración cambió; vuelve a abrir el campo.']);
            }
            $field->fill($data);
            $field->has_fixed_value = $hasFixedValue;
            $field->fixed_value = $hasFixedValue ? $value : null;
            $field->needs_configuration = false;
            $field->save();
            $referenced = $current->fields()->where('input_type', InputType::Image)->where('has_fixed_value', true)->get()
                ->map(fn (PipelineField $field): mixed => is_array($field->fixed_value) ? ($field->fixed_value['__upload'] ?? null) : null)
                ->filter(fn (mixed $id): bool => is_int($id))->unique()->values()->all();
            $this->uploads->retainForReference($referenced);
            if ($upload !== null) {
                $current->inputUploads()->syncWithoutDetaching([$upload->id]);
            }
            $current->inputUploads()->whereNotIn('input_uploads.id', $referenced)->get()->each(
                fn ($upload) => $current->inputUploads()->detach($upload->id),
            );
            $errors = $this->readiness->evaluate($current);
            $current->update(['readiness_errors' => $errors, 'config_revision' => $current->config_revision + 1]);
            if ($current->is_active && $errors !== []) {
                $this->activation->deactivate($current);
            }

            return $field;
        });
    }
}
