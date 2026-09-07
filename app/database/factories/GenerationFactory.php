<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Generation;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Generation $generation): void {
            $generation->campaign_id = $generation->pipeline->campaign_id;
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'pipeline_id' => fn (array $attributes) => Pipeline::factory()->create([
                'campaign_id' => $attributes['campaign_id'],
            ])->id,
            'user_id' => User::factory(),
            'kind' => 'series',
            'parent_piece_id' => null,
            'request_id' => Str::uuid(),
            'execution_snapshot' => [],
            'status' => 'pending',
            'failure_reason' => null,
            'error_message' => null,
            'retryable' => false,
            'restarted_from_generation_id' => null,
            'submission_started_at' => null,
            'submitted_at' => null,
            'completed_at' => null,
            'seen_at' => null,
        ];
    }
}
