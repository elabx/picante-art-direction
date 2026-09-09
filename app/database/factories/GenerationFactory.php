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
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function snapshot(array $overrides = []): array
    {
        return array_replace_recursive([
            'engine' => 'krea',
            'provider_ref' => 'ver-test',
            'pipeline_label' => 'Test',
            'config_revision' => 1,
            'credential_source' => 'studio',
            'kind' => 'series',
            'inputs' => [],
            'labels' => [],
            'bindings' => ['image' => null, 'prompt' => null],
            'schema' => [],
            'source_piece' => null,
        ], $overrides);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Generation $generation): void {
            $generation->pipeline->campaigns()->syncWithoutDetaching([$generation->campaign_id]);
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
            'pipeline_id' => Pipeline::factory(),
            'campaign_id' => fn (array $attributes) => Pipeline::findOrFail($attributes['pipeline_id'])->campaigns()->first()?->id ?? Campaign::factory()->create()->id,
            'user_id' => User::factory(),
            'kind' => 'series',
            'parent_piece_id' => null,
            'request_id' => Str::uuid(),
            'execution_snapshot' => self::snapshot(),
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
