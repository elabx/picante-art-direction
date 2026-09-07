<?php

namespace App\Services\Media;

final class ImageInspector
{
    /** @var array<string, string> */
    private const MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @return array{mime: string, width: int, height: int, ext: string} */
    public function inspect(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;

        if ($info === false || ! is_string($mime) || ! isset(self::MAP[$mime])) {
            throw new InvalidImageException('La imagen recibida no es JPEG, PNG ni WebP.');
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false || imagesx($image) !== (int) $info[0] || imagesy($image) !== (int) $info[1]) {
            throw new InvalidImageException('La imagen recibida no es JPEG, PNG ni WebP.');
        }

        // GDImage objects are released by PHP; imagedestroy() is deprecated in PHP 8.5.
        unset($image);

        return [
            'mime' => $mime,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'ext' => self::MAP[$mime],
        ];
    }
}
