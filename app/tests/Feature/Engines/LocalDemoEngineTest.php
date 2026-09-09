<?php

use App\Engines\EngineResolver;
use App\Engines\FakeEngine;
use App\Engines\Krea\KreaEngine;
use App\Enums\GenerationStatus;
use App\Jobs\DownloadOutputJob;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Models\Brand;
use App\Models\Generation;
use App\Models\Piece;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('completes a local demo through fresh worker engines and stores all four outputs without provider HTTP', function (): void {
    Queue::fake([RunGenerationJob::class, PollGenerationJob::class, DownloadOutputJob::class]);
    Storage::fake('pieces');
    Http::preventStrayRequests();
    app()->detectEnvironment(fn (): string => 'local');
    config(['media.fake_engine' => true]);
    $provider = new AppServiceProvider(app());
    $provider->register();
    $provider->boot();
    $engine = app(EngineResolver::class)->forBrand(Brand::factory()->create());
    expect($engine)->toBeInstanceOf(FakeEngine::class);
    expect($engine->describe('fbe97b3b-d810-4de4-859f-49aa2a7887ab')->inputSchema)->not->toBeNull();
    $generation = Generation::factory()->create();

    app()->call([new RunGenerationJob($generation->id), 'handle']);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Submitted);
    $freshEngine = app(EngineResolver::class)->forBrand($generation->campaign->brand);
    expect($freshEngine)->not->toBe($engine);
    app()->call([new PollGenerationJob($generation->jobs()->sole()->id), 'handle']);
    expect($generation->outputs()->count())->toBe(4);
    foreach ($generation->outputs()->get() as $output) {
        app()->call([new DownloadOutputJob($output->id), 'handle']);
    }

    expect($generation->fresh()->status)->toBe(GenerationStatus::Completed);
    expect(Piece::count())->toBe(4);
    foreach ($generation->pieces()->get() as $piece) {
        Storage::disk('pieces')->assertExists($piece->storage_path);
        expect([$piece->width, $piece->height])->toBe([1024, 768]);
        expect($piece->is_4k)->toBeFalse();
    }
    expect($generation->pieces->map(fn (Piece $piece): string => hash('sha256', Storage::disk('pieces')->get($piece->storage_path)))->unique())->toHaveCount(4);
    Http::assertSentCount(4);
    Http::assertNotSent(fn ($request): bool => ! str_starts_with($request->url(), 'https://muse-demo.invalid/'));
    Queue::assertPushed(PollGenerationJob::class, 1);
    Queue::assertPushed(DownloadOutputJob::class, 4);
});

it('does not enable the demo binding outside local or with the flag disabled', function (string $environment, bool $enabled): void {
    Http::preventStrayRequests();
    app()->detectEnvironment(fn (): string => $environment);
    config(['media.fake_engine' => $enabled, 'media.krea.key' => 'test-only-key']);
    $provider = new AppServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(app()->bound(FakeEngine::class))->toBeFalse();
    expect(app(EngineResolver::class)->forBrand(Brand::factory()->create()))->toBeInstanceOf(KreaEngine::class);
    Http::assertNothingSent();
})->with([['production', true], ['testing', true], ['local', false]]);
