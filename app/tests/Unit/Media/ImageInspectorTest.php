<?php

use App\Services\Media\ImageInspector;
use App\Services\Media\InvalidImageException;

it('reads dimensions and mime for each supported image type', function (string $file, string $mime, string $ext): void {
    $bytes = file_get_contents(base_path("tests/Fixtures/images/{$file}"));

    $result = (new ImageInspector)->inspect((string) $bytes);

    expect($result)->toMatchArray([
        'mime' => $mime,
        'width' => 4,
        'height' => 3,
        'ext' => $ext,
    ]);
})->with([
    ['tiny.png', 'image/png', 'png'],
    ['tiny.jpg', 'image/jpeg', 'jpg'],
    ['tiny.webp', 'image/webp', 'webp'],
]);

it('rejects non-images and truncated images', function (): void {
    expect(fn (): array => (new ImageInspector)->inspect('hello'))
        ->toThrow(InvalidImageException::class);

    $png = file_get_contents(base_path('tests/Fixtures/images/tiny.png'));

    expect(fn (): array => (new ImageInspector)->inspect(substr((string) $png, 0, 40)))
        ->toThrow(InvalidImageException::class);
});
