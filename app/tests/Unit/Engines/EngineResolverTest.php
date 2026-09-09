<?php

use App\Engines\EngineResolver;
use App\Engines\FakeEngine;
use App\Engines\Krea\KreaEngine;
use App\Engines\KreaException;
use App\Models\Brand;
use App\Models\Generation;
use Database\Factories\GenerationFactory;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('returns the bound fake engine for isolated callers', function () {
    $fake = fakeEngine();

    expect(app(EngineResolver::class)->forBrand(Brand::factory()->make()))->toBe($fake);
});

it('does not fall back when the selected pinned brand credential is missing', function () {
    config()->set('media.krea.key', 'studio-key');
    $brand = Brand::factory()->make(['krea_api_key' => null]);

    expect(fn () => app(EngineResolver::class)->forSource($brand, 'brand'))
        ->toThrow(KreaException::class, 'Clave de acceso inválida o faltante.');
});

it('builds a krea engine with the explicitly selected studio credential', function () {
    config()->set('media.krea.key', 'studio-key');
    config()->set('media.krea.base_url', 'https://api.krea.test');
    $brand = Brand::factory()->make(['krea_api_key' => 'brand-key']);
    Http::fake(['*/node-apps/ver-1' => Http::response(['name' => 'Creador', 'node_app_version_id' => 'ver-1'])]);

    $engine = app(EngineResolver::class)->forSource($brand, 'studio');
    $engine->describe('ver-1');

    expect($engine)->toBeInstanceOf(KreaEngine::class);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer studio-key'));
});

it('provides worker-safe default execution snapshots through factories and pest helpers', function () {
    $snapshot = GenerationFactory::snapshot(['credential_source' => 'brand']);

    expect($snapshot)->toMatchArray([
        'engine' => 'krea',
        'provider_ref' => 'ver-test',
        'credential_source' => 'brand',
        'bindings' => ['image' => null, 'prompt' => null],
    ])->and(snapshot())->toBe(GenerationFactory::snapshot())
        ->and(Generation::factory()->raw()['execution_snapshot'])->toBe(GenerationFactory::snapshot());
});

it('resolves the studio engine from the configured studio key', function (): void {
    config()->set('media.krea.key', 'studio-key');

    expect(app(EngineResolver::class)->forStudio())->toBeInstanceOf(KreaEngine::class);
});

it('refuses a studio engine without a key', function (): void {
    config()->set('media.krea.key', null);

    expect(fn () => app(EngineResolver::class)->forStudio())->toThrow(KreaException::class);
});

it('returns the fake engine for the studio when it is bound', function (): void {
    fakeEngine();

    expect(app(EngineResolver::class)->forStudio())->toBeInstanceOf(FakeEngine::class);
});
