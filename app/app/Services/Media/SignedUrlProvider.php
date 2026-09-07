<?php

namespace App\Services\Media;

interface SignedUrlProvider
{
    public function url(string $disk, string $path, \DateTimeInterface $expiresAt, bool $download = false, ?string $filename = null): string;
}
