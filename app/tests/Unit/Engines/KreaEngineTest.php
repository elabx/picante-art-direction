<?php

use App\Engines\Krea\KreaEngine;
use App\Engines\KreaException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->engine = new KreaEngine('dummy-key', 'https://api.krea.test');
});

it('describes a node app version from the sanitized gate a fixture', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/krea/schema-generator.json')), true, flags: JSON_THROW_ON_ERROR);
    Http::fake(['https://api.krea.test/node-apps/ver-1' => Http::response($fixture)]);

    $schema = $this->engine->describe('ver-1');

    expect($schema->name)->toBe('CREADOR SANTANDER')
        ->and($schema->inputSchema['required'])->toBe(['describe_la_escena']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dummy-key'));
});

it('accepts an array response and keeps every job id', function () {
    Http::fake(['https://api.krea.test/node-apps/ver-1/execute' => Http::response([['job_id' => 'a', 'status' => 'queued'], ['job_id' => 'b', 'status' => 'queued']])]);

    $outcome = $this->engine->submit('ver-1', ['describe_la_escena' => 'x']);

    expect($outcome->isAccepted())->toBeTrue()
        ->and($outcome->jobIds)->toBe(['a', 'b']);
});

it('accepts a single object response', function () {
    Http::fake(['*/execute' => Http::response(['job_id' => 'solo', 'status' => 'queued'])]);

    expect($this->engine->submit('ver-1', [])->jobIds)->toBe(['solo']);
});

it('rejects on 4xx with the mapped message and nested detail', function () {
    Http::fake(['*/execute' => Http::response(['error' => ['message' => 'sin saldo']], 402)]);

    $outcome = $this->engine->submit('ver-1', []);

    expect($outcome->isRejected())->toBeTrue()
        ->and($outcome->error)->toBe('Saldo insuficiente. sin saldo')
        ->and($outcome->httpStatus)->toBe(402);
});

it('redacts its exact api key from provider error details', function () {
    Http::fake(['*/execute' => Http::response(['message' => 'Bearer dummy-key https://cdn.test/output.png?signature=secret'], 400)]);

    $outcome = $this->engine->submit('ver-1', []);

    expect($outcome->error)->toBe('Solicitud inválida. Bearer [redacted] https://cdn.test/output.png');
});

it('returns unknown on connection failure and never retries the post', function () {
    $calls = 0;
    Http::fake(['*/execute' => function () use (&$calls) {
        $calls++;

        return Http::failedConnection();
    }]);

    $outcome = $this->engine->submit('ver-1', []);

    expect($outcome->isUnknown())->toBeTrue()
        ->and($calls)->toBe(1);
});

it('treats redirects as unknown without a follow-up post', function () {
    Http::fake(['*/execute' => Http::response('', 302, ['Location' => 'https://elsewhere.test/execute'])]);

    $outcome = $this->engine->submit('ver-1', []);

    expect($outcome->isUnknown())->toBeTrue();
    Http::assertSentCount(1);
});

it('treats a 5xx submit response as unknown', function () {
    Http::fake(['*/execute' => Http::response(['message' => 'upstream failure'], 503)]);

    $outcome = $this->engine->submit('ver-1', []);

    expect($outcome->isUnknown())->toBeTrue()
        ->and($outcome->error)->toBe('Error interno del servicio. upstream failure');
});

it('wraps a connection failure on describe into KreaException', function () {
    $calls = 0;
    Http::fake(['*/node-apps/*' => function () use (&$calls) {
        $calls++;

        return Http::failedConnection();
    }]);

    expect(fn () => $this->engine->describe('ver-1'))->toThrow(KreaException::class, 'No se pudo conectar con el servicio.');
    expect($calls)->toBe(2);
});

it('pings the node apps endpoint and maps provider failures without exposing the key', function (): void {
    Http::fake(['https://api.krea.test/node-apps?limit=1' => Http::response(['message' => 'Bearer dummy-key'], 401)]);

    expect(fn () => $this->engine->ping())
        ->toThrow(KreaException::class, 'Clave de acceso inválida o faltante. Bearer [redacted]');
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.krea.test/node-apps?limit=1'
        && $request->hasHeader('Authorization', 'Bearer dummy-key'));
});

it('pings the node apps endpoint successfully', function (): void {
    Http::fake(['https://api.krea.test/node-apps?limit=1' => Http::response([])]);

    $this->engine->ping();

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.krea.test/node-apps?limit=1'
        && $request->hasHeader('Authorization', 'Bearer dummy-key'));
});

it('retries one transient describe response before returning its schema', function () {
    $calls = 0;
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/krea/schema-generator.json')), true, flags: JSON_THROW_ON_ERROR);
    Http::fake(['*/node-apps/ver-1' => function () use (&$calls, $fixture) {
        $calls++;

        return $calls === 1 ? Http::response(['message' => 'temporary failure'], 503) : Http::response($fixture);
    }]);

    $schema = $this->engine->describe('ver-1');

    expect($schema->versionId)->toBe('fbe97b3b-d810-4de4-859f-49aa2a7887ab')
        ->and($calls)->toBe(2);
});

it('does not retry a nontransient describe response', function () {
    Http::fake(['*/node-apps/ver-1' => Http::response(['message' => 'invalid request'], 400)]);

    expect(fn () => $this->engine->describe('ver-1'))->toThrow(KreaException::class, 'Solicitud inválida. invalid request');
    Http::assertSentCount(1);
});

it('treats an empty malformed or job id-less body as unknown', function (mixed $body) {
    Http::fake(['*/execute' => Http::response($body, 200)]);

    expect($this->engine->submit('ver-1', [])->isUnknown())->toBeTrue();
})->with([[[]], ['not an object'], [['status' => 'queued']]]);

it('inspects a job and normalizes status', function (string $native, string $normalized) {
    Http::fake(['https://api.krea.test/jobs/j1' => Http::response(['job_id' => 'j1', 'status' => $native, 'position' => 3, 'result' => ['urls' => ['https://c/x.png']]])]);

    $observation = $this->engine->inspect('j1');

    expect($observation->normalizedStatus)->toBe($normalized)
        ->and($observation->nativeStatus)->toBe($native)
        ->and($observation->queuePosition)->toBe(3);
})->with([
    ['queued', 'pending'],
    ['backlogged', 'pending'],
    ['sampling', 'pending'],
    ['intermediate-complete', 'pending'],
    ['completed', 'completed'],
    ['failed', 'failed'],
    ['cancelled', 'cancelled'],
]);

it('normalizes and redacts inspect errors before returning them', function () {
    Http::fake(['*/jobs/j1' => Http::response(['status' => 'failed', 'error' => ['message' => 'Bearer dummy-key']])]);

    $observation = $this->engine->inspect('j1');

    expect($observation->error)->toBe(['message' => 'Bearer [redacted]']);
});

it('redacts an inspect error nested in the retained result', function () {
    Http::fake(['*/jobs/j1' => Http::response(['status' => 'failed', 'result' => ['error' => 'Bearer dummy-key']])]);

    $observation = $this->engine->inspect('j1');

    expect($observation->result)->toBe(['error' => 'Bearer [redacted]'])
        ->and($observation->error)->toBe(['message' => 'Bearer [redacted]']);
});
