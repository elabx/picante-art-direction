<?php

namespace App\Services\Generation;

use InvalidArgumentException;

final class FourKRule
{
    /**
     * @return array{width: int, height: int}
     */
    public static function target(int $width, int $height): array
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Las dimensiones deben ser positivas.');
        }

        $scale = 3840 / max($width, $height);

        return [
            'width' => (int) round($width * $scale),
            'height' => (int) round($height * $scale),
        ];
    }

    public static function accepts(int $sourceWidth, int $sourceHeight, int $outputWidth, int $outputHeight): bool
    {
        return self::target($sourceWidth, $sourceHeight) === [
            'width' => $outputWidth,
            'height' => $outputHeight,
        ];
    }

    public static function meetsTarget(int $width, int $height): bool
    {
        return max($width, $height) >= 3840;
    }
}
