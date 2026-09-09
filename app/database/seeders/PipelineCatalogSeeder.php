<?php

namespace Database\Seeders;

use App\Enums\PipelineKind;
use App\Models\Pipeline;
use Illuminate\Database\Seeder;

class PipelineCatalogSeeder extends Seeder
{
    /** @var array<string, array{kind: PipelineKind, label: string}> */
    private const APPS = [
        'generator' => ['kind' => PipelineKind::Generator, 'label' => 'Creador Santander v3'],
        'editor' => ['kind' => PipelineKind::Editor, 'label' => 'Editor Santander v2'],
        'upscaler' => ['kind' => PipelineKind::Upscaler, 'label' => 'Upscaler Santander'],
        'skechers' => ['kind' => PipelineKind::Generator, 'label' => 'Fotos Skechers'],
        'invierno' => ['kind' => PipelineKind::Generator, 'label' => 'Generador Invierno'],
    ];

    public function run(): void
    {
        $configured = (array) config('media.krea.test_apps', []);
        $seen = [];

        foreach (self::APPS as $key => $app) {
            $providerRef = trim((string) ($configured[$key] ?? ''));

            if ($providerRef === '') {
                $this->command?->line("Catálogo: {$key} sin id, se omite.");

                continue;
            }

            if (in_array($providerRef, $seen, true)) {
                $this->command?->warn("Catálogo: {$key} repite el id de otra app, se omite.");

                continue;
            }

            $seen[] = $providerRef;

            Pipeline::query()->firstOrCreate(
                ['engine' => 'krea', 'provider_ref' => $providerRef],
                ['kind' => $app['kind'], 'label' => $app['label']],
            );
        }
    }
}
