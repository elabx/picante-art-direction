<?php

namespace App\Engines\Krea;

use App\Engines\Data\EngineSchema;
use App\Engines\Data\JobObservation;
use App\Engines\Data\OutputRef;
use App\Engines\Data\SubmissionOutcome;
use App\Engines\ImageEngine;
use App\Engines\KreaErrorMessages;
use App\Engines\KreaException;
use App\Engines\ResultFlattener;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class KreaEngine implements ImageEngine
{
    private const PENDING = ['backlogged', 'queued', 'scheduled', 'processing', 'sampling', 'intermediate-complete'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.krea.ai',
    ) {}

    public function describe(string $providerRef): EngineSchema
    {
        try {
            $response = $this->http(15)->get("/node-apps/{$providerRef}");
        } catch (ConnectionException) {
            throw new KreaException(KreaErrorMessages::network(), null);
        }

        $payload = $this->json($response);

        if (! $response->successful()) {
            throw new KreaException(
                KreaErrorMessages::forStatus($response->status(), $this->providerDetail($payload)),
                $response->status(),
            );
        }

        $data = is_array($payload) ? $payload : [];
        $schema = $data['input_openapi_schema'] ?? null;

        return new EngineSchema(
            (string) ($data['name'] ?? $providerRef),
            (string) ($data['node_app_version_id'] ?? $providerRef),
            is_array($schema) ? $schema : null,
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function submit(string $providerRef, array $inputs): SubmissionOutcome
    {
        try {
            $response = $this->http(60)->post("/node-apps/{$providerRef}/execute", $inputs);
        } catch (ConnectionException) {
            return SubmissionOutcome::unknown(KreaErrorMessages::network());
        }

        $payload = $this->json($response);
        $detail = $this->providerDetail($payload);

        if ($response->status() >= 500) {
            return SubmissionOutcome::unknown(KreaErrorMessages::forStatus($response->status(), $detail));
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            return SubmissionOutcome::unknown(KreaErrorMessages::forStatus($response->status(), $detail));
        }

        if (! $response->successful()) {
            return SubmissionOutcome::rejected(
                KreaErrorMessages::forStatus($response->status(), $detail),
                $response->status(),
            );
        }

        if (! is_array($payload)) {
            return SubmissionOutcome::unknown('La respuesta no incluyó job_id.');
        }

        $jobs = array_is_list($payload) ? $payload : [$payload];
        $jobIds = array_values(array_filter(array_map(function (mixed $job): ?string {
            if (! is_array($job) || ! isset($job['job_id']) || ! is_scalar($job['job_id'])) {
                return null;
            }

            $jobId = (string) $job['job_id'];

            return filled($jobId) ? $jobId : null;
        }, $jobs)));

        return $jobIds === []
            ? SubmissionOutcome::unknown('La respuesta no incluyó job_id.')
            : SubmissionOutcome::accepted($jobIds);
    }

    public function inspect(string $jobId): JobObservation
    {
        try {
            $response = $this->http(15)->get("/jobs/{$jobId}");
        } catch (ConnectionException) {
            throw new KreaException(KreaErrorMessages::network(), null);
        }

        $payload = $this->json($response);

        if (! $response->successful()) {
            throw new KreaException(
                KreaErrorMessages::forStatus($response->status(), $this->providerDetail($payload)),
                $response->status(),
            );
        }

        $data = is_array($payload) ? $payload : [];
        $data = array_is_list($data) ? ($data[0] ?? []) : $data;
        $data = is_array($data) ? $data : [];
        $nativeStatus = is_string($data['status'] ?? null) ? $data['status'] : 'queued';
        $result = is_array($data['result'] ?? null) ? $data['result'] : null;
        $error = $data['error'] ?? ($result['error'] ?? null);

        if ($result !== null && array_key_exists('error', $result)) {
            $result['error'] = $this->sanitizeErrorValue($result['error']);
        }

        return new JobObservation(
            $this->normalizeStatus($nativeStatus),
            $nativeStatus,
            isset($data['position']) && is_numeric($data['position']) ? (int) $data['position'] : null,
            $result,
            $this->errorArray($error),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<OutputRef>
     */
    public function outputs(array $result): array
    {
        return ResultFlattener::flatten($result);
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    private function json(Response $response): mixed
    {
        return $response->json();
    }

    private function providerDetail(mixed $payload): ?string
    {
        $detail = $this->detail($payload);

        return $detail === null ? null : str_replace($this->apiKey, '[redacted]', $detail);
    }

    private function detail(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['message', 'error', 'detail'] as $key) {
            if (array_key_exists($key, $value)) {
                $detail = $this->detail($value[$key]);

                if ($detail !== null) {
                    return $detail;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function errorArray(mixed $error): ?array
    {
        if (is_string($error)) {
            return ['message' => $this->sanitizeProviderString($error)];
        }

        if (! is_array($error)) {
            return null;
        }

        return $this->sanitizeErrorArray($error);
    }

    /** @param array<string|int, mixed> $error */
    private function sanitizeErrorArray(array $error): array
    {
        foreach ($error as $key => $value) {
            if (is_string($value)) {
                $error[$key] = $this->sanitizeProviderString($value);
            } elseif (is_array($value)) {
                $error[$key] = $this->sanitizeErrorArray($value);
            }
        }

        return $error;
    }

    private function sanitizeProviderString(string $value): string
    {
        return KreaErrorMessages::sanitizeDetail(str_replace($this->apiKey, '[redacted]', $value)) ?? '';
    }

    private function sanitizeErrorValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeProviderString($value);
        }

        return is_array($value) ? $this->sanitizeErrorArray($value) : $value;
    }

    private function normalizeStatus(string $nativeStatus): string
    {
        if (in_array($nativeStatus, self::PENDING, true)) {
            return 'pending';
        }

        return in_array($nativeStatus, ['completed', 'failed', 'cancelled'], true) ? $nativeStatus : 'pending';
    }
}
