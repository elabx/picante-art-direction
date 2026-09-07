<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\PipelineField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineField>
 */
class PipelineFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'name' => fake()->unique()->word(),
            'source_schema' => ['type' => 'string'],
            'input_type' => 'string',
            'required' => false,
            'label_override' => null,
            'help_text' => null,
            'visibility' => 'visible',
            'has_fixed_value' => false,
            'fixed_value' => null,
            'role' => 'none',
            'stale' => false,
            'needs_configuration' => false,
            'sort_order' => 0,
        ];
    }
}
