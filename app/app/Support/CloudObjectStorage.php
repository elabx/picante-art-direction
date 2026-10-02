<?php

namespace App\Support;

/**
 * Laravel Cloud replaces the attached disk's configuration wholesale after the
 * config loads: it drops the disk root, turns off exceptions and leaves any
 * other disk without a bucket. The media disks share that single bucket, so
 * both receive the Cloud credentials while keeping their own prefixes.
 */
final class CloudObjectStorage
{
    /** @var list<string> */
    private const MEDIA_DISKS = ['inputs', 'pieces'];

    public static function configure(): void
    {
        if (! isset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG'])) {
            return;
        }

        $cloudDisks = json_decode((string) $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'], true);
        $attachedDisk = collect(is_array($cloudDisks) ? $cloudDisks : [])
            ->pluck('disk')
            ->first(fn (mixed $disk): bool => in_array($disk, self::MEDIA_DISKS, true));

        if ($attachedDisk === null) {
            return;
        }

        $sharedBucket = config("filesystems.disks.{$attachedDisk}");

        foreach (self::MEDIA_DISKS as $disk) {
            config(["filesystems.disks.{$disk}" => [...$sharedBucket, 'root' => $disk, 'throw' => true]]);
        }
    }
}
