<?php

namespace Database\Factories;

use App\Enums\PipelineKind;
use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'generator',
            'engine' => 'krea',
            'provider_ref' => fake()->uuid(),
            'label' => fake()->sentence(3),
            'input_schema' => null,
            'schema_fetched_at' => null,
            'config_revision' => 1,
            'readiness_errors' => null,
            'is_ready' => false,
        ];
    }

    public function generator(): static
    {
        return $this->state(['kind' => PipelineKind::Generator]);
    }

    public function editor(): static
    {
        return $this->state(['kind' => PipelineKind::Editor]);
    }

    public function upscaler(): static
    {
        return $this->state(['kind' => PipelineKind::Upscaler]);
    }

    public function ready(): static
    {
        return $this->state(['is_ready' => true, 'readiness_errors' => []]);
    }
}
