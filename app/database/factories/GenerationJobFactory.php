<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\GenerationJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationJob>
 */
class GenerationJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_id' => Generation::factory(),
            'provider_job_id' => fake()->uuid(),
            'status' => null,
            'normalized_status' => 'pending',
            'queue_position' => null,
            'result' => null,
            'error' => null,
            'last_polled_at' => null,
            'next_poll_at' => null,
            'poll_failures' => 0,
        ];
    }
}
