<?php

namespace App\Support;

use DateTimeInterface;

final class Media
{
    public static function ttl(): DateTimeInterface
    {
        return now()->addSeconds((int) config('media.signed_url_ttl', 600));
    }

    public static function contentDisposition(?string $filename): string
    {
        $safeFilename = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $filename ?? '');

        return 'attachment; filename="'.($safeFilename ?? '').'"';
    }
}
