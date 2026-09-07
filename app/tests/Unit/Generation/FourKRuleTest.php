<?php

use App\Services\Generation\FourKRule;

it('computes exact 4K targets', function (int $width, int $height, int $targetWidth, int $targetHeight): void {
    expect(FourKRule::target($width, $height))->toBe(['width' => $targetWidth, 'height' => $targetHeight]);
})->with([
    [1920, 1080, 3840, 2160],
    [1080, 1920, 2160, 3840],
    [1024, 1024, 3840, 3840],
    [1000, 667, 3840, 2561],
]);

it('accepts only exact 4K target matches', function (): void {
    expect(FourKRule::accepts(1920, 1080, 3840, 2160))->toBeTrue()
        ->and(FourKRule::accepts(1920, 1080, 3840, 2158))->toBeFalse()
        ->and(FourKRule::accepts(1024, 1024, 3840, 2160))->toBeFalse();
});

it('recognizes images whose long edge meets the 4K target', function (): void {
    expect(FourKRule::meetsTarget(3840, 2160))->toBeTrue()
        ->and(FourKRule::meetsTarget(2160, 3839))->toBeFalse();
});

it('rejects non-positive source dimensions', function (): void {
    expect(fn (): array => FourKRule::target(0, 1080))->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => FourKRule::target(1920, -1))->toThrow(InvalidArgumentException::class);
});
