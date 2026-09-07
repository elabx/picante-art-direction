<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Piece;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Piece>
 */
class PieceFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Piece $piece): void {
            $piece->generation_id = $piece->output->generation_id;
            $piece->campaign_id = $piece->output->generation->campaign_id;
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
            'generation_id' => Generation::factory(),
            'generation_output_id' => fn (array $attributes) => GenerationOutput::factory()->create([
                'generation_id' => $attributes['generation_id'],
            ])->id,
            'campaign_id' => fn (array $attributes) => Generation::query()
                ->findOrFail($attributes['generation_id'])
                ->campaign_id,
            'kind' => 'original',
            'parent_piece_id' => null,
            'root_piece_id' => null,
            'storage_path' => fake()->uuid().'.png',
            'source_url' => 'https://cdn.example/img.png',
            'width' => 1024,
            'height' => 1024,
            'bytes' => 1000,
            'mime_type' => 'image/png',
            'index' => 0,
            'is_4k' => false,
            'selected' => false,
        ];
    }
}
