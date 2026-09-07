<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'kind' => 'generator',
            'engine' => 'krea',
            'provider_ref' => fake()->uuid(),
            'label' => fake()->sentence(3),
            'input_schema' => null,
            'schema_fetched_at' => null,
            'config_revision' => 1,
            'readiness_errors' => null,
            'sort_order' => 0,
            'is_active' => false,
        ];
    }
}
