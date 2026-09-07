<?php

use App\Services\Media\DownloadException;
use App\Services\Media\ImageInspector;
use App\Services\Media\InvalidImageException;
use App\Services\Media\ResultDownloader;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('downloads and validates an https image without an authorization header', function (): void {
    Http::fake([
        'https://cdn.test/a.png' => Http::response(fixtureImage('tiny.png'), 200, ['Content-Type' => 'image/png']),
    ]);

    $result = (new ResultDownloader(new ImageInspector))->download('https://cdn.test/a.png');

    expect($result['width'])->toBe(4)
        ->and($result['ext'])->toBe('png')
        ->and($result['bytes'])->toBe(fixtureImage('tiny.png'));
    Http::assertSent(fn ($request): bool => ! $request->hasHeader('Authorization'));
});

it('refuses non-https urls, missing hosts, and urls with credentials', function (string $url): void {
    expect(fn (): array => (new ResultDownloader(new ImageInspector))->download($url))
        ->toThrow(DownloadException::class);
})->with([
    'http scheme' => 'http://cdn.test/a.png',
    'missing host' => 'https:///a.png',
    'url credentials' => 'https://user:secret@cdn.test/a.png',
]);

it('refuses redirects', function (): void {
    Http::fake([
        'https://cdn.test/r' => Http::response('', 302, ['Location' => 'https://elsewhere.test/image.png']),
    ]);

    expect(fn (): array => (new ResultDownloader(new ImageInspector))->download('https://cdn.test/r'))
        ->toThrow(DownloadException::class);
});

it('refuses bodies declared above the configured limit', function (): void {
    config(['media.max_result_bytes' => 10]);
    Http::fake([
        'https://cdn.test/big.png' => Http::response(fixtureImage('tiny.png'), 200, ['Content-Length' => '99']),
    ]);

    expect(fn (): array => (new ResultDownloader(new ImageInspector))->download('https://cdn.test/big.png'))
        ->toThrow(DownloadException::class);
});

it('refuses bodies exceeding the configured limit while streamed', function (): void {
    config(['media.max_result_bytes' => 10]);
    Http::fake([
        'https://cdn.test/nolen.png' => Http::response(fixtureImage('tiny.png'), 200),
    ]);

    expect(fn (): array => (new ResultDownloader(new ImageInspector))->download('https://cdn.test/nolen.png'))
        ->toThrow(DownloadException::class);
});

it('propagates invalid image failures from successful downloads', function (): void {
    Http::fake([
        'https://cdn.test/text' => Http::response('not an image', 200),
    ]);

    expect(fn (): array => (new ResultDownloader(new ImageInspector))->download('https://cdn.test/text'))
        ->toThrow(InvalidImageException::class);
});

function fixtureImage(string $filename): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/images/{$filename}"));
}
