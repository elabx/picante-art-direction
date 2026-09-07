<?php

namespace App\Engines;

final class KreaErrorMessages
{
    public static function forStatus(?int $status, ?string $detail): string
    {
        $base = match (true) {
            $status === 400 => 'Solicitud inválida.',
            $status === 401 => 'Clave de acceso inválida o faltante.',
            $status === 402 => 'Saldo insuficiente.',
            $status === 404 => 'No se encontró el flujo.',
            $status === 429 => 'El servicio está saturado. Intenta en unos minutos.',
            $status !== null && $status >= 500 => 'Error interno del servicio.',
            default => 'Error '.($status ?? '').'.',
        };

        $detail = self::sanitizeDetail($detail);

        return filled($detail) ? $base.' '.$detail : $base;
    }

    public static function network(): string
    {
        return 'No se pudo conectar con el servicio. Intenta de nuevo en unos segundos.';
    }

    public static function sanitizeDetail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $sanitized = preg_replace('#data:[a-z]+/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i', '[data-url]', $detail);
        $sanitized = preg_replace('#Bearer\s+\S+#i', 'Bearer [redacted]', $sanitized ?? '');
        $sanitized = preg_replace('#(https?://[^\s?]+)\?[^\s]+#i', '$1', $sanitized ?? '');
        $sanitized = preg_replace_callback('#[A-Za-z0-9_\-:]{24,}#', function (array $match): string {
            return preg_match('/[A-Za-z]/', $match[0]) && preg_match('/\d/', $match[0]) ? '[redacted]' : $match[0];
        }, $sanitized ?? '');

        return mb_strcut($sanitized ?? '', 0, 2048);
    }
}
