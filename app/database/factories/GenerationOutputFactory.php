<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<GenerationOutput>
 */
class GenerationOutputFactory extends Factory
{
    public function configure(): static
    {
        return $this
            ->afterMaking(function (GenerationOutput $output): void {
                $output->generation_id = $output->job->generation_id;
            })
            ->sequence(fn (Sequence $sequence): array => ['index' => $sequence->index]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_id' => Generation::factory(),
            'generation_job_id' => fn (array $attributes) => GenerationJob::factory()->create([
                'generation_id' => $attributes['generation_id'],
            ])->id,
            'index' => 0,
            'source_url' => 'https://cdn.example/img.png',
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => null,
            'error_message' => null,
        ];
    }
}
