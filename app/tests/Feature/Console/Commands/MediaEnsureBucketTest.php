<?php

use App\Console\Commands\MediaEnsureBucket;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sdk;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command as ConsoleCommand;

beforeEach(function () {
    config()->set('filesystems.disks.inputs', [
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.test',
        'use_path_style_endpoint' => true,
    ]);
});

it('creates the configured bucket when it is missing', function () {
    $handler = new MockHandler([
        new AwsException('Not Found', new Command('HeadBucket'), [
            'response' => new Response(404),
        ]),
        new Result,
    ]);

    $exitCode = app(MediaEnsureBucket::class)->handle(new Sdk(['handler' => $handler]));

    expect($exitCode)->toBe(ConsoleCommand::SUCCESS)
        ->and($handler->getLastCommand()->getName())->toBe('CreateBucket')
        ->and($handler->getLastCommand()['Bucket'])->toBe('test-bucket')
        ->and($handler)->toHaveCount(0);
});

it('does not recreate the configured bucket when it already exists', function () {
    $handler = new MockHandler([new Result]);

    $exitCode = app(MediaEnsureBucket::class)->handle(new Sdk(['handler' => $handler]));

    expect($exitCode)->toBe(ConsoleCommand::SUCCESS)
        ->and($handler->getLastCommand()->getName())->toBe('HeadBucket')
        ->and($handler)->toHaveCount(0);
});

it('surfaces errors other than a missing bucket response', function () {
    $handler = new MockHandler([
        new AwsException('Forbidden', new Command('HeadBucket'), [
            'response' => new Response(403),
        ]),
    ]);

    expect(fn () => app(MediaEnsureBucket::class)->handle(new Sdk(['handler' => $handler])))
        ->toThrow(AwsException::class, 'Forbidden');

    expect($handler->getLastCommand()->getName())->toBe('HeadBucket')
        ->and($handler)->toHaveCount(0);
});
