<?php

use App\Models\Pipeline;
use Database\Seeders\PipelineCatalogSeeder;

it('creates one catalog entry per configured id and skips empty and duplicate ids', function (): void {
    config()->set('media.krea.test_apps', [
        'generator' => 'gen-1',
        'editor' => 'edit-1',
        'upscaler' => 'edit-1',
        'skechers' => 'sk-1',
        'invierno' => '',
    ]);

    $this->seed(PipelineCatalogSeeder::class);

    expect(Pipeline::query()->orderBy('id')->get()->map(fn (Pipeline $pipeline): array => [$pipeline->kind->value, $pipeline->label, $pipeline->provider_ref, $pipeline->is_ready])->all())
        ->toBe([
            ['generator', 'Creador Santander v3', 'gen-1', false],
            ['editor', 'Editor Santander v2', 'edit-1', false],
            ['generator', 'Fotos Skechers', 'sk-1', false],
        ]);
});

it('is idempotent and never touches schema, readiness, or the engine', function (): void {
    config()->set('media.krea.test_apps', ['generator' => 'gen-1', 'editor' => null, 'upscaler' => null, 'skechers' => null, 'invierno' => null]);
    $this->seed(PipelineCatalogSeeder::class);
    $pipeline = Pipeline::query()->sole();
    $pipeline->update(['label' => 'Renombrada', 'input_schema' => ['properties' => []], 'is_ready' => true, 'readiness_errors' => []]);
    fakeEngine()->duringDescribe(fn () => test()->fail('Seeder must never describe an app.'));

    $this->seed(PipelineCatalogSeeder::class);

    expect(Pipeline::query()->count())->toBe(1)
        ->and($pipeline->fresh()->label)->toBe('Renombrada')
        ->and($pipeline->fresh()->is_ready)->toBeTrue()
        ->and($pipeline->fresh()->input_schema)->toBe(['properties' => []]);
});
