<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);

    $this->fixtureDirectory = sys_get_temp_dir().'/krea-smoke-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->fixtureDirectory);

    config()->set('media.krea.key', 'dummy-key');
    config()->set('media.krea.base_url', 'https://api.krea.test');
    config()->set('media.krea.fixture_path', $this->fixtureDirectory);
});

afterEach(function (): void {
    File::deleteDirectory($this->fixtureDirectory);
});

it('records sanitized multi-job evidence and output measurements from one submission', function (): void {
    $imagePath = $this->fixtureDirectory.'/source.png';
    file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL9VwAAAABJRU5ErkJggg==', true));

    Http::fake([
        'https://api.krea.test/node-apps/ver-1/execute' => Http::response([
            ['job_id' => 'a0f7e7fd-63c0-4a2f-8ee4-56dcd0141111', 'debug' => 'Bearer dummy-key data:image/png;base64,secret', 'dummy-key' => 'plain dummy-key'],
            ['job_id' => 'b4e26a48-29f4-4ec4-a268-0b4e0cde2222', 'url' => 'https://cdn.krea.test/result.png?signature=secret'],
        ]),
        'https://api.krea.test/jobs/a0f7e7fd-63c0-4a2f-8ee4-56dcd0141111' => Http::response([
            'job_id' => 'a0f7e7fd-63c0-4a2f-8ee4-56dcd0141111',
            'status' => 'completed',
            'result' => ['urls' => ['https://cdn.krea.test/a.png?signature=secret']],
        ]),
        'https://api.krea.test/jobs/b4e26a48-29f4-4ec4-a268-0b4e0cde2222' => Http::response([
            'job_id' => 'b4e26a48-29f4-4ec4-a268-0b4e0cde2222',
            'status' => 'completed',
            'result' => ['urls' => ['https://cdn.krea.test/b.png?signature=secret']],
        ]),
        'https://cdn.krea.test/a.png?signature=secret' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL9VwAAAABJRU5ErkJggg==', true), 200, ['Content-Type' => 'image/png']),
        'https://cdn.krea.test/b.png?signature=secret' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL9VwAAAABJRU5ErkJggg==', true), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('krea:smoke', [
        'versionId' => 'ver-1',
        '--input' => ['prompt=hola'],
        '--image' => ["photo={$imagePath}"],
        '--name' => 'multi-job',
    ])->expectsOutputToContain('cdn.krea.test 1x1')
        ->assertSuccessful();

    $submit = (string) file_get_contents($this->fixtureDirectory.'/multi-job-submit.json');
    $jobs = (string) file_get_contents($this->fixtureDirectory.'/multi-job-job.json');
    $result = json_decode((string) file_get_contents($this->fixtureDirectory.'/multi-job-result.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($submit)->not->toContain('dummy-key')
        ->not->toContain('data:image')
        ->not->toContain('a0f7e7fd-63c0-4a2f-8ee4-56dcd0141111')
        ->toContain('job-1')
        ->and($jobs)->not->toContain('?signature=')
        ->not->toContain(base64_encode((string) file_get_contents($imagePath)))
        ->and($result['outputs'])->toHaveCount(2)
        ->and($result['outputs'][0])->toMatchArray(['host' => 'cdn.krea.test', 'width' => 1, 'height' => 1]);
    Http::assertSentCount(5);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.krea.test/node-apps/ver-1/execute'
        && str_starts_with((string) $request->data()['photo'], 'data:image/png;base64,'));
});

it('rejects unsafe names and existing reservations before submitting', function (): void {
    File::put($this->fixtureDirectory.'/already.reserved', 'reserved');
    Http::fake();

    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => '../unsafe'])
        ->expectsOutputToContain('nombre')
        ->assertFailed();
    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => 'already'])
        ->expectsOutputToContain('ya existe')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects input and image key collisions before submitting', function (): void {
    $imagePath = $this->fixtureDirectory.'/source.png';
    file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL9VwAAAABJRU5ErkJggg==', true));
    Http::fake();

    $this->artisan('krea:smoke', [
        'versionId' => 'ver-1',
        '--input' => ['photo=caption'],
        '--image' => ["photo={$imagePath}"],
        '--name' => 'collision',
    ])->expectsOutputToContain('no puede usarse')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects a missing key and malformed or duplicate options before submitting', function (): void {
    Http::fake();
    config()->set('media.krea.key', null);

    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => 'missing-key'])
        ->expectsOutputToContain('Falta configurar')
        ->assertFailed();

    config()->set('media.krea.key', 'dummy-key');
    $this->artisan('krea:smoke', [
        'versionId' => 'ver-1',
        '--input' => ['not-a-pair', 'prompt=one', 'prompt=two'],
        '--name' => 'bad-input',
    ])->assertFailed();

    Http::assertNothingSent();
});

it('rejects unsupported and oversized images before submitting', function (): void {
    $textPath = $this->fixtureDirectory.'/source.txt';
    $largePath = $this->fixtureDirectory.'/large.png';
    file_put_contents($textPath, 'not an image');
    file_put_contents($largePath, str_repeat('x', 20 * 1024 * 1024 + 1));
    Http::fake();

    $this->artisan('krea:smoke', [
        'versionId' => 'ver-1',
        '--image' => ["photo={$textPath}"],
        '--name' => 'bad-image',
    ])->assertFailed();
    $this->artisan('krea:smoke', [
        'versionId' => 'ver-1',
        '--image' => ["photo={$largePath}"],
        '--name' => 'large-image',
    ])->assertFailed();

    Http::assertNothingSent();
});

it('keeps a reservation after an ambiguous submission and does not resubmit it', function (): void {
    Http::fake(['https://api.krea.test/node-apps/ver-1/execute' => Http::failedConnection()]);

    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => 'ambiguous'])
        ->assertFailed();
    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => 'ambiguous'])
        ->expectsOutputToContain('ya existe')
        ->assertFailed();

    Http::assertSentCount(1);
    expect($this->fixtureDirectory.'/ambiguous.reserved')->toBeFile();
});

it('stops on a terminal job failure without resubmitting', function (): void {
    Http::fake([
        'https://api.krea.test/node-apps/ver-1/execute' => Http::response(['job_id' => 'job-a']),
        'https://api.krea.test/jobs/job-a' => Http::response(['job_id' => 'job-a', 'status' => 'failed', 'error' => ['message' => 'no credit']]),
    ]);

    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => 'failed-job'])
        ->assertFailed();

    Http::assertSentCount(2);
    expect($this->fixtureDirectory.'/failed-job-submit.json')->toBeFile()
        ->and($this->fixtureDirectory.'/failed-job-job.json')->toBeFile()
        ->and($this->fixtureDirectory.'/failed-job.reserved')->toBeFile();
});

it('times out after ten minutes of pending status without another submission', function (): void {
    Http::fake([
        'https://api.krea.test/node-apps/ver-1/execute' => Http::response(['job_id' => 'slow-job']),
        'https://api.krea.test/jobs/slow-job' => Http::response(['job_id' => 'slow-job', 'status' => 'queued']),
    ]);

    $this->artisan('krea:smoke', ['versionId' => 'ver-1', '--name' => 'timed-out'])
        ->expectsOutputToContain('10 minutos')
        ->assertFailed();

    Sleep::assertSleptTimes(147);
    expect($this->fixtureDirectory.'/timed-out-submit.json')->toBeFile()
        ->and($this->fixtureDirectory.'/timed-out-job.json')->toBeFile()
        ->and($this->fixtureDirectory.'/timed-out.reserved')->toBeFile();
});
