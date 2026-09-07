<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class ArtDirectorSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'ad@picante.local'],
            [
                'name' => 'Art Director',
                'password' => env('SEED_AD_PASSWORD', 'password'),
                'role' => UserRole::ArtDirector,
            ],
        );
    }
}
