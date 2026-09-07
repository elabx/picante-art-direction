<?php

use App\Engines\KreaErrorMessages;

it('maps krea http statuses to spanish messages', function (?int $status, string $expected) {
    expect(KreaErrorMessages::forStatus($status, null))->toBe($expected);
})->with([
    [400, 'Solicitud inválida.'],
    [401, 'Clave de acceso inválida o faltante.'],
    [402, 'Saldo insuficiente.'],
    [404, 'No se encontró el flujo.'],
    [429, 'El servicio está saturado. Intenta en unos minutos.'],
    [500, 'Error interno del servicio.'],
    [503, 'Error interno del servicio.'],
    [418, 'Error 418.'],
]);

it('appends detail and exposes the network message', function () {
    expect(KreaErrorMessages::forStatus(400, 'campo x'))->toBe('Solicitud inválida. campo x')
        ->and(KreaErrorMessages::network())->toBe('No se pudo conectar con el servicio. Intenta de nuevo en unos segundos.');
});

it('redacts key-shaped tokens data urls bearer tokens and signed url queries before truncating', function () {
    $detail = 'bad key 52ce52f9e9ba4ad49c9250a65138a356pXPP0L9k and data:image/png;base64,AAAA and Bearer secret-token and https://cdn.test/image.png?signature=secret '.str_repeat('x', 3000);
    $out = KreaErrorMessages::sanitizeDetail($detail);

    expect($out)->not->toContain('52ce52f9')
        ->not->toContain('data:image')
        ->not->toContain('secret-token')
        ->not->toContain('?signature=')
        ->and(strlen($out))->toBeLessThanOrEqual(2048);
});

it('redacts non-base64 data urls with optional media parameters', function () {
    $out = KreaErrorMessages::sanitizeDetail('invalid data:text/plain;charset=utf-8,secret-value and data:image/svg+xml,%3Csvg%3E');

    expect($out)->not->toContain('data:text/plain')
        ->not->toContain('data:image/svg+xml')
        ->not->toContain('secret-value');
});
