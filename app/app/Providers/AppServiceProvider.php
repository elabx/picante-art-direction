<?php

namespace App\Providers;

use App\Engines\EngineResolver;
use App\Services\Media\CloudFrontSignedUrlProvider;
use App\Services\Media\PresignedS3UrlProvider;
use App\Services\Media\SignedUrlProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EngineResolver::class);
        $this->app->bind(SignedUrlProvider::class, fn (): SignedUrlProvider => config('media.url_provider') === 'cloudfront'
            ? new CloudFrontSignedUrlProvider
            : new PresignedS3UrlProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
