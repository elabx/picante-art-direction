<?php

use App\Services\Media\CloudFrontSignedUrlProvider;
use App\Services\Media\PresignedS3UrlProvider;
use App\Services\Media\SignedUrlProvider;
use App\Support\Media;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('produces a cloudfront signed url with an encoded path and expected query parameters', function (): void {
    $privateKeyPath = writeCloudFrontPrivateKey();
    $expiresAt = now()->addMinutes(10);

    try {
        config([
            'media.cloudfront' => [
                'domain' => 'd111.cloudfront.net',
                'key_pair_id' => 'KPID',
                'private_key_path' => $privateKeyPath,
            ],
            'filesystems.disks.pieces.root' => 'pieces',
        ]);

        $url = (new CloudFrontSignedUrlProvider)->url('pieces', 'b c/#?.png', $expiresAt, true, 'pieza.png');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect(parse_url($url, PHP_URL_HOST))->toBe('d111.cloudfront.net')
            ->and(parse_url($url, PHP_URL_PATH))->toBe('/pieces/b%20c/%23%3F.png')
            ->and($query)->toHaveKeys(['Expires', 'Signature', 'Key-Pair-Id', 'response-content-disposition'])
            ->and($query['Expires'])->toBe((string) $expiresAt->getTimestamp())
            ->and($query['Key-Pair-Id'])->toBe('KPID')
            ->and($query['response-content-disposition'])->toBe('attachment; filename="pieza.png"');
    } finally {
        unlink($privateKeyPath);
    }
});

it('removes control characters and quoting from cloudfront attachment filenames', function (): void {
    $privateKeyPath = writeCloudFrontPrivateKey();

    try {
        config([
            'media.cloudfront' => [
                'domain' => 'd111.cloudfront.net',
                'key_pair_id' => 'KPID',
                'private_key_path' => $privateKeyPath,
            ],
            'filesystems.disks.pieces.root' => 'pieces',
        ]);

        $url = (new CloudFrontSignedUrlProvider)->url('pieces', '1.png', now()->addMinutes(10), true, "piece\"\r\nname.png");
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query['response-content-disposition'])->toBe('attachment; filename="piecename.png"');
    } finally {
        unlink($privateKeyPath);
    }
});

it('delegates presigned urls to the requested disk with download options', function (): void {
    $expiresAt = now()->addMinutes(10);
    $disk = Mockery::mock();
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->with('b/c/1.png', $expiresAt, ['ResponseContentDisposition' => 'attachment; filename="pieza.png"'])
        ->andReturn('https://s3.test/signed');
    Storage::shouldReceive('disk')->once()->with('pieces')->andReturn($disk);

    $url = (new PresignedS3UrlProvider)->url('pieces', 'b/c/1.png', $expiresAt, true, 'pieza.png');

    expect($url)->toBe('https://s3.test/signed');
});

it('resolves the provider from config', function (): void {
    config(['media.url_provider' => 'presigned']);

    expect(app(SignedUrlProvider::class))->toBeInstanceOf(PresignedS3UrlProvider::class);
});

it('calculates the signed url expiry from the configured ttl', function (): void {
    $now = Carbon::parse('2026-09-07 12:00:00');
    Carbon::setTestNow($now);
    config(['media.signed_url_ttl' => 45]);

    try {
        expect(Media::ttl())->toEqual($now->copy()->addSeconds(45));
    } finally {
        Carbon::setTestNow();
    }
});

function writeCloudFrontPrivateKey(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'cf');
    file_put_contents($path, $pem);

    return $path;
}
