<?php

namespace App\Services\Media;

use App\Support\Media;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;

final class PresignedS3UrlProvider implements SignedUrlProvider
{
    public function url(string $disk, string $path, DateTimeInterface $expiresAt, bool $download = false, ?string $filename = null): string
    {
        return Storage::disk($disk)->temporaryUrl(
            $path,
            $expiresAt,
            $download ? ['ResponseContentDisposition' => Media::contentDisposition($filename)] : [],
        );
    }
}
