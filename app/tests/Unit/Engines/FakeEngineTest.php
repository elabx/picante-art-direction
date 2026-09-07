<?php

use App\Engines\Data\EngineSchema;
use App\Engines\Data\JobObservation;
use App\Engines\FakeEngine;
use App\Engines\KreaException;

it('records submissions and returns programmed outcomes', function () {
    $engine = new FakeEngine;
    $engine->willAccept(['job-1', 'job-2']);
    $engine->setJob('job-1', new JobObservation('completed', 'completed', null, ['urls' => ['https://x/a.png']], null));

    $out = $engine->submit('ver-1', ['prompt' => 'hola']);

    expect($out->isAccepted())->toBeTrue()
        ->and($out->jobIds)->toBe(['job-1', 'job-2'])
        ->and($engine->submissions)->toBe([['ref' => 'ver-1', 'inputs' => ['prompt' => 'hola']]])
        ->and($engine->inspect('job-1')->isTerminal())->toBeTrue()
        ->and($engine->inspect('job-2')->normalizedStatus)->toBe('pending');
});

it('represents rejected and unknown submission outcomes', function () {
    $engine = new FakeEngine;
    $engine->willReject('invalid input', 422);
    $rejected = $engine->submit('ver-1', []);
    $engine->willBeUnknown();
    $unknown = $engine->submit('ver-1', []);

    expect($rejected->isRejected())->toBeTrue()
        ->and($rejected->error)->toBe('invalid input')
        ->and($rejected->httpStatus)->toBe(422)
        ->and($unknown->isUnknown())->toBeTrue()
        ->and($unknown->error)->toBe('timeout')
        ->and($unknown->httpStatus)->toBeNull();
});

it('describes configured schemas including a null input schema', function () {
    $engine = new FakeEngine;
    $engine->withSchema('ver-1', ['type' => 'object'], 'Generator');
    $engine->withSchema('ver-2', null);

    expect($engine->describe('ver-1'))->toEqual(new EngineSchema('Generator', 'ver-1', ['type' => 'object']))
        ->and($engine->describe('ver-2'))->toEqual(new EngineSchema('Fake app', 'ver-2', null));
});

it('raises a KreaException when a schema is missing', function () {
    try {
        (new FakeEngine)->describe('missing');
    } catch (KreaException $exception) {
        expect($exception->getMessage())->toBe('No se encontró el flujo.')
            ->and($exception->httpStatus)->toBe(404)
            ->and($exception->detail)->toBeNull();

        return;
    }

    $this->fail('Missing schemas must raise KreaException.');
});

it('marks completed failed and cancelled observations as terminal', function () {
    expect(new JobObservation('completed', 'done', null, null, null))->isTerminal()->toBeTrue()
        ->and(new JobObservation('failed', 'error', null, null, ['message' => 'no']))->isTerminal()->toBeTrue()
        ->and(new JobObservation('cancelled', 'cancelled', null, null, null))->isTerminal()->toBeTrue()
        ->and(new JobObservation('pending', 'queued', 3, null, null))->isTerminal()->toBeFalse();
});

it('flattens result urls deterministically and deduplicates', function () {
    $refs = (new FakeEngine)->outputs([
        'urls' => [
            'https://x/a.png',
            ['https://x/b.png', 'https://x/a.png'],
            'k' => 'https://x/c.png',
            'http://x/nope.png',
        ],
    ]);

    expect(array_map(fn ($ref) => [$ref->index, $ref->url], $refs))
        ->toBe([[0, 'https://x/a.png'], [1, 'https://x/b.png'], [2, 'https://x/c.png']]);
});

it('records ping calls without contacting a provider', function () {
    $engine = new FakeEngine;

    $engine->ping();
    $engine->ping();

    expect($engine->pingCalls)->toBe(2);
});
