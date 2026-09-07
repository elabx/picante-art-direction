<?php

use App\Engines\Krea\KreaEngine;
use Illuminate\Support\Facades\Http;

it('replays each recorded Krea submission and job fixture through the engine', function (): void {
    $submitFixtures = glob(base_path('tests/Fixtures/krea/*-submit.json')) ?: [];

    if ($submitFixtures === []) {
        $this->markTestSkipped('Live Gate B fixtures are pending explicit approval and execution.');
    }

    foreach ($submitFixtures as $submitPath) {
        $stem = basename($submitPath, '-submit.json');
        $submit = json_decode((string) file_get_contents($submitPath), true, flags: JSON_THROW_ON_ERROR);
        $jobFixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/krea/{$stem}-job.json")), true, flags: JSON_THROW_ON_ERROR);
        $resultFixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/krea/{$stem}-result.json")), true, flags: JSON_THROW_ON_ERROR);
        $jobs = $jobFixture['jobs'];

        Http::preventStrayRequests();
        Http::fake(array_merge([
            'https://api.krea.test/node-apps/version/execute' => Http::response($submit),
        ], collect($jobs)->mapWithKeys(fn (array $job, string $id): array => ["https://api.krea.test/jobs/{$id}" => Http::response($job)])->all()));

        $engine = new KreaEngine('dummy-key', 'https://api.krea.test');
        $outcome = $engine->submit('version', []);

        expect($outcome->jobIds)->toBe(array_keys($jobs));

        $outputCount = 0;
        foreach ($outcome->jobIds as $jobId) {
            $outputCount += count($engine->outputs($engine->inspect($jobId)->result ?? []));
        }

        expect($outputCount)->toBe(count($resultFixture['outputs']));
    }
});
