<?php

declare(strict_types=1);

use App\Support\CloudObjectStorage;
use Illuminate\Foundation\Cloud;

afterEach(function (): void {
    unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG']);
});

// Laravel Cloud Object Storage is Cloudflare R2, which rejects per-object ACLs.
// A disk-level visibility makes Flysystem and Livewire send x-amz-acl on every write.
it('does not set per-object visibility on the media disks', function (string $disk): void {
    expect(config("filesystems.disks.{$disk}"))->not->toHaveKey('visibility');
})->with(['inputs', 'pieces']);

it('shares the attached Cloud bucket between the media disks under their own prefixes', function (): void {
    $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([[
        'disk' => 'inputs',
        'access_key_id' => 'cloud-key',
        'access_key_secret' => 'cloud-secret',
        'bucket' => 'cloud-bucket',
        'url' => 'https://cloud-bucket.example.test',
        'endpoint' => 'https://r2.example.test',
        'is_default' => true,
    ]]);
    Cloud::configureDisks(app());

    CloudObjectStorage::configure();

    foreach (['inputs', 'pieces'] as $disk) {
        expect(config("filesystems.disks.{$disk}"))->toMatchArray([
            'driver' => 's3',
            'key' => 'cloud-key',
            'secret' => 'cloud-secret',
            'bucket' => 'cloud-bucket',
            'endpoint' => 'https://r2.example.test',
            'region' => 'auto',
            'root' => $disk,
            'throw' => true,
        ]);
    }
});

it('leaves the media disks untouched outside Laravel Cloud', function (): void {
    $before = config('filesystems.disks');

    CloudObjectStorage::configure();

    expect(config('filesystems.disks'))->toBe($before);
});
