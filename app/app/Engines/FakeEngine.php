<?php

namespace App\Engines;

use App\Engines\Data\EngineSchema;
use App\Engines\Data\JobObservation;
use App\Engines\Data\OutputRef;
use App\Engines\Data\SubmissionOutcome;
use Closure;
use Illuminate\Support\Str;
use Throwable;

final class FakeEngine implements ImageEngine
{
    private bool $localDemo = false;

    public static function localDemo(): self
    {
        $engine = new self;
        $engine->localDemo = true;
        foreach (['generator', 'editor', 'upscaler'] as $kind) {
            $fixture = json_decode(file_get_contents(base_path("tests/Fixtures/krea/schema-{$kind}.json")), true, flags: JSON_THROW_ON_ERROR);
            $engine->withSchema($fixture['node_app_version_id'], $fixture['input_openapi_schema'], $fixture['name']);
        }

        return $engine;
    }

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

        return $this->nextOutcome ?? SubmissionOutcome::accepted([
            $this->localDemo ? 'muse-demo-'.Str::uuid() : 'job-'.count($this->submissions),
        ]);
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

        if ($this->localDemo && str_starts_with($jobId, 'muse-demo-') && Str::isUuid(Str::after($jobId, 'muse-demo-'))) {
            return new JobObservation('completed', 'completed', null, [
                'urls' => array_map(fn (int $index): string => "https://muse-demo.invalid/{$jobId}/{$index}.png", range(0, 3)),
            ], null);
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
