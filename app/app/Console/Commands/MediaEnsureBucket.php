<?php

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use Aws\Sdk;
use Illuminate\Console\Command;

class MediaEnsureBucket extends Command
{
    protected $signature = 'media:ensure-bucket';

    protected $description = 'Create the configured media bucket when it does not exist';

    public function handle(Sdk $aws): int
    {
        $disk = config('filesystems.disks.inputs');
        $client = $aws->createS3([
            'version' => 'latest',
            'region' => $disk['region'],
            'credentials' => [
                'key' => $disk['key'],
                'secret' => $disk['secret'],
            ],
            'endpoint' => $disk['endpoint'],
            'use_path_style_endpoint' => $disk['use_path_style_endpoint'],
        ]);

        try {
            $client->headBucket(['Bucket' => $disk['bucket']]);
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }

            $client->createBucket(['Bucket' => $disk['bucket']]);
        }

        return self::SUCCESS;
    }
}
