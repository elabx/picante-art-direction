<?php

namespace App\Engines;

use App\Engines\Data\EngineSchema;
use App\Engines\Data\JobObservation;
use App\Engines\Data\OutputRef;
use App\Engines\Data\SubmissionOutcome;

interface ImageEngine
{
    public function describe(string $providerRef): EngineSchema;

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function submit(string $providerRef, array $inputs): SubmissionOutcome;

    public function inspect(string $jobId): JobObservation;

    /**
     * @return list<OutputRef>
     */
    public function outputs(array $result): array;
}
