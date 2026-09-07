<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\InputUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InputUpload>
 */
class InputUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'user_id' => User::factory(),
            'storage_path' => 'uploads/'.fake()->uuid().'.png',
            'mime_type' => 'image/png',
            'bytes' => 1000,
            'width' => 1024,
            'height' => 1024,
            'finalized_at' => null,
        ];
    }
}
