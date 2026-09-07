<?php

namespace App\Engines;

use App\Engines\Krea\KreaEngine;
use App\Models\Brand;

final class EngineResolver
{
    public function forBrand(Brand $brand): ImageEngine
    {
        return $this->forSource($brand, $brand->resolveKreaKeySource());
    }

    public function forSource(Brand $brand, string $source): ImageEngine
    {
        if (app()->bound(FakeEngine::class)) {
            return app(FakeEngine::class);
        }

        if (! in_array($source, ['brand', 'studio'], true)) {
            throw new KreaException(KreaErrorMessages::forStatus(401, null), 401);
        }

        $key = $source === 'brand' ? $brand->krea_api_key : config('media.krea.key');

        if (blank($key)) {
            throw new KreaException(KreaErrorMessages::forStatus(401, null), 401);
        }

        return new KreaEngine((string) $key, (string) config('media.krea.base_url'));
    }
}
