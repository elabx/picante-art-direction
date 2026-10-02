<?php

declare(strict_types=1);

// Laravel Cloud Object Storage is Cloudflare R2, which rejects per-object ACLs.
// A disk-level visibility makes Flysystem and Livewire send x-amz-acl on every write.
it('does not set per-object visibility on the media disks', function (string $disk): void {
    expect(config("filesystems.disks.{$disk}"))->not->toHaveKey('visibility');
})->with(['inputs', 'pieces']);
