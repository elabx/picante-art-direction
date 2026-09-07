<?php

namespace App\Engines;

use App\Engines\Data\EngineSchema;
use App\Engines\Data\JobObservation;
use App\Engines\Data\OutputRef;
use App\Engines\Data\SubmissionOutcome;
use Closure;
use Throwable;

final class FakeEngine implements ImageEngine
{
    /**
     * @var list<array{ref: string, inputs: array<string, mixed>}>
     */
    public array $submissions = [];

    public int $pingCalls = 0;

    /** @var array<string, Throwable> */
    private array $inspectFailures = [];

    /**
     * @var array<string, EngineSchema>
     */
    private array $schemas = [];

    private ?SubmissionOutcome $nextOutcome = null;

    private ?Closure $duringDescribe = null;

    /**
     * @var array<string, JobObservation>
     */
    private array $jobs = [];

    public function withSchema(string $ref, ?array $schema, string $name = 'Fake app'): self
    {
        $this->schemas[$ref] = new EngineSchema($name, $ref, $schema);

        return $this;
    }

    /**
     * @param  Closure(string): void  $callback
     */
    public function duringDescribe(Closure $callback): self
    {
        $this->duringDescribe = $callback;

        return $this;
    }

    /**
     * @param  list<string>  $jobIds
     */
    public function willAccept(array $jobIds): self
    {
        $this->nextOutcome = SubmissionOutcome::accepted($jobIds);

        return $this;
    }

    public function willReject(string $msg, int $status = 400): self
    {
        $this->nextOutcome = SubmissionOutcome::rejected($msg, $status);

        return $this;
    }

    public function willBeUnknown(): self
    {
        $this->nextOutcome = SubmissionOutcome::unknown('timeout');

        return $this;
    }

    public function setJob(string $jobId, JobObservation $obs): self
    {
        unset($this->inspectFailures[$jobId]);
        $this->jobs[$jobId] = $obs;

        return $this;
    }

    public function describe(string $providerRef): EngineSchema
    {
        if ($this->duringDescribe !== null) {
            ($this->duringDescribe)($providerRef);
        }

        return $this->schemas[$providerRef] ?? throw new KreaException('No se encontró el flujo.', 404);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function submit(string $providerRef, array $inputs): SubmissionOutcome
    {
        $this->submissions[] = ['ref' => $providerRef, 'inputs' => $inputs];

        return $this->nextOutcome ?? SubmissionOutcome::accepted(['job-'.count($this->submissions)]);
    }

    public function failInspect(string $jobId, Throwable $exception): self
    {
        $this->inspectFailures[$jobId] = $exception;

        return $this;
    }

    public function inspect(string $jobId): JobObservation
    {
        if (isset($this->inspectFailures[$jobId])) {
            throw $this->inspectFailures[$jobId];
        }

        return $this->jobs[$jobId] ?? new JobObservation('pending', 'queued', null, null, null);
    }

    /**
     * @return list<OutputRef>
     */
    public function outputs(array $result): array
    {
        return ResultFlattener::flatten($result);
    }

    public function ping(): void
    {
        $this->pingCalls++;
    }
}
