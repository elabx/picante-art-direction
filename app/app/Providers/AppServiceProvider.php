<?php

namespace App\Providers;

use App\Engines\EngineResolver;
use App\Engines\FakeEngine;
use App\Services\Media\CloudFrontSignedUrlProvider;
use App\Services\Media\PresignedS3UrlProvider;
use App\Services\Media\SignedUrlProvider;
use App\Support\CloudObjectStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        CloudObjectStorage::configure();
        $this->app->singleton(EngineResolver::class);
        if ($this->app->environment('local') && config('media.fake_engine')) {
            $this->app->bind(FakeEngine::class, fn (): FakeEngine => FakeEngine::localDemo());
        }
        $this->app->bind(SignedUrlProvider::class, fn (): SignedUrlProvider => config('media.url_provider') === 'cloudfront'
            ? new CloudFrontSignedUrlProvider
            : new PresignedS3UrlProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('local') && config('media.fake_engine')) {
            Http::fake(function ($request) {
                if ($request->method() === 'GET' && preg_match('~\\Ahttps://muse-demo\\.invalid/muse-demo-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/([0-3])\\.png\\z~', $request->url(), $matches) === 1) {
                    return Http::response(file_get_contents(base_path("tests/Fixtures/images/demo-{$matches[1]}.png")), 200, ['Content-Type' => 'image/png']);
                }

                return null;
            });
        }
    }
}
