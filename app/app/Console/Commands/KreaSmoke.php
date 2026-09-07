<?php

namespace App\Console\Commands;

use App\Engines\Data\JobObservation;
use App\Engines\Krea\KreaEngine;
use App\Engines\KreaErrorMessages;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

final class KreaSmoke extends Command
{
    protected $signature = 'krea:smoke
        {versionId : Krea node app version ID}
        {--input=* : Input key=value}
        {--image=* : Image input key=local path}
        {--name= : Safe fixture name}';

    protected $description = 'Run one bounded Krea smoke submission and record sanitized response fixtures';

    /** @var array<string, string> */
    private array $jobAliases = [];

    /** @var array<string, string> */
    private array $urlAliases = [];

    /** @var array<string, mixed> */
    private array $jobs = [];

    private string $fixtureDirectory;

    private string $fixtureName;

    private string $apiKey;

    public function handle(): int
    {
        $key = config('media.krea.key');

        if (! is_string($key) || blank($key)) {
            $this->error('Falta configurar la clave de Krea.');

            return self::FAILURE;
        }

        $this->apiKey = $key;

        try {
            $this->fixtureName = $this->validatedName();
            $this->fixtureDirectory = (string) config('media.krea.fixture_path', base_path('tests/Fixtures/krea'));
            $inputs = $this->inputs();
            $this->ensureRequestWithinLimit($inputs);
            $this->reserveName();

            $engine = new KreaEngine(
                $key,
                (string) config('media.krea.base_url'),
                $this->recordResponse(...),
            );
            $outcome = $engine->submit((string) $this->argument('versionId'), $inputs);

            if (! $outcome->isAccepted()) {
                $this->error('La solicitud no quedó confirmada. No se volverá a enviar con este nombre.');

                return self::FAILURE;
            }

            $observations = $this->poll($engine, $outcome->jobIds);

            if ($observations === null) {
                return self::FAILURE;
            }

            $this->writeJson($this->resultPath(), ['format' => 'krea-smoke-result-v1', 'outputs' => $this->downloadOutputs($engine, $observations)]);
            File::delete($this->reservationPath());
            $this->info('Evidencia sanitizada guardada para '.$this->fixtureName.'.');

            return self::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('No se pudo completar la ejecución. La reserva se conserva para impedir otro envío.');

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function inputs(): array
    {
        $inputs = $this->keyValueOptions('input');

        foreach ($this->keyValueOptions('image') as $key => $path) {
            if (array_key_exists($key, $inputs)) {
                throw new \InvalidArgumentException('Una clave no puede usarse en --input y --image.');
            }

            $inputs[$key] = $this->imageDataUrl($path);
        }

        return $inputs;
    }

    /** @return array<string, string> */
    private function keyValueOptions(string $option): array
    {
        $values = $this->option($option);
        $pairs = is_array($values) ? $values : [];
        $parsed = [];

        foreach ($pairs as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                throw new \InvalidArgumentException("Cada --{$option} debe tener formato clave=valor.");
            }

            [$key, $value] = explode('=', $pair, 2);

            if (! preg_match('/\A[A-Za-z][A-Za-z0-9_.-]*\z/', $key) || array_key_exists($key, $parsed)) {
                throw new \InvalidArgumentException("Las claves de --{$option} deben ser únicas y seguras.");
            }

            $parsed[$key] = $value;
        }

        return $parsed;
    }

    private function imageDataUrl(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('No se pudo leer una imagen indicada.');
        }

        $size = filesize($path);

        if ($size === false || $size > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('Una imagen supera el límite de 20 MiB.');
        }

        $mime = mime_content_type($path);

        if (! is_string($mime) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('La imagen debe ser JPEG, PNG o WebP.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \InvalidArgumentException('No se pudo leer una imagen indicada.');
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /** @param array<string, mixed> $inputs */
    private function ensureRequestWithinLimit(array $inputs): void
    {
        $payload = json_encode($inputs, JSON_THROW_ON_ERROR);

        if (strlen($payload) > (int) config('media.max_request_bytes')) {
            throw new \InvalidArgumentException('La solicitud supera el límite permitido.');
        }
    }

    private function validatedName(): string
    {
        $name = $this->option('name');
        $name = is_string($name) && filled($name) ? $name : 'smoke-'.now()->format('Ymd-His');

        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $name)) {
            throw new \InvalidArgumentException('El nombre debe ser un nombre de archivo seguro.');
        }

        return $name;
    }

    private function reserveName(): void
    {
        File::ensureDirectoryExists($this->fixtureDirectory);

        foreach ([$this->reservationPath(), $this->submitPath(), $this->jobPath(), $this->resultPath()] as $path) {
            if (File::exists($path)) {
                throw new \RuntimeException('El nombre de evidencia ya existe.');
            }
        }

        $reservation = @fopen($this->reservationPath(), 'x');

        if ($reservation === false) {
            throw new \RuntimeException('El nombre de evidencia ya existe.');
        }

        fclose($reservation);
    }

    /**
     * @param  list<string>  $jobIds
     * @return array<string, JobObservation>|null
     */
    private function poll(KreaEngine $engine, array $jobIds): ?array
    {
        $observations = [];
        $deadline = now()->addMinutes(10);

        while (count($observations) < count($jobIds)) {
            if (now()->addSeconds(15)->greaterThan($deadline)) {
                $this->error('Se agotaron los 10 minutos de espera.');

                return null;
            }

            foreach ($jobIds as $jobId) {
                if (array_key_exists($jobId, $observations)) {
                    continue;
                }

                $observation = $engine->inspect($jobId);
                $this->line($this->safeString($observation->nativeStatus));

                if (in_array($observation->normalizedStatus, ['failed', 'cancelled'], true)) {
                    $this->error('El trabajo terminó sin resultado.');

                    return null;
                }

                if ($observation->normalizedStatus === 'completed') {
                    $observations[$jobId] = $observation;
                }
            }

            if (count($observations) === count($jobIds)) {
                break;
            }

            Sleep::for(4)->seconds();
        }

        return $observations;
    }

    /**
     * @param  array<string, JobObservation>  $observations
     * @return list<array{url: string, host: string, bytes: int, width: ?int, height: ?int}>
     */
    private function downloadOutputs(KreaEngine $engine, array $observations): array
    {
        $outputs = [];

        foreach ($observations as $observation) {
            foreach ($engine->outputs($observation->result ?? []) as $output) {
                $url = $output->url;
                $parts = parse_url($url);

                if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
                    throw new \RuntimeException('La salida no usa HTTPS.');
                }

                try {
                    $response = Http::withoutRedirecting()->connectTimeout(5)->timeout(30)->get($url);
                } catch (ConnectionException) {
                    throw new \RuntimeException('No se pudo descargar una salida.');
                }

                if (! $response->successful()) {
                    throw new \RuntimeException('No se pudo descargar una salida.');
                }

                $body = $response->body();

                if (strlen($body) > (int) config('media.max_result_bytes')) {
                    throw new \RuntimeException('Una salida supera el límite permitido.');
                }

                $dimensions = @getimagesizefromstring($body);
                $metadata = [
                    'url' => $this->safeString($url),
                    'host' => $this->safeString($parts['host']),
                    'bytes' => strlen($body),
                    'width' => is_array($dimensions) ? $dimensions[0] : null,
                    'height' => is_array($dimensions) ? $dimensions[1] : null,
                ];
                $outputs[] = $metadata;
                $this->line($metadata['host'].' '.($metadata['width'] ?? '?').'x'.($metadata['height'] ?? '?').' '.$metadata['bytes'].' bytes');
            }
        }

        return $outputs;
    }

    private function recordResponse(string $operation, string $reference, mixed $payload): void
    {
        $this->registerAliases($payload);

        if ($operation === 'submit') {
            $this->writeJson($this->submitPath(), $this->sanitize($payload));

            return;
        }

        $this->jobs[$this->jobAlias($reference)] = $this->sanitize($payload);
        $this->writeJson($this->jobPath(), ['format' => 'krea-smoke-job-v1', 'jobs' => $this->jobs]);
    }

    private function registerAliases(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key === 'job_id' && is_scalar($item) && filled((string) $item)) {
                    $this->jobAlias((string) $item);
                }

                $this->registerAliases($item);
            }

            return;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && str_starts_with($value, 'https://')) {
            $this->urlAlias($value);
        }
    }

    private function jobAlias(string $jobId): string
    {
        return $this->jobAliases[$jobId] ??= 'job-'.(count($this->jobAliases) + 1);
    }

    private function urlAlias(string $url): string
    {
        if (isset($this->urlAliases[$url])) {
            return $this->urlAliases[$url];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? $host : 'output.invalid';

        return $this->urlAliases[$url] = 'https://'.$host.'/output-'.(count($this->urlAliases) + 1);
    }

    private function safeString(string $value): string
    {
        $replacements = array_merge($this->jobAliases, $this->urlAliases);
        uksort($replacements, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        return KreaErrorMessages::sanitizeDetail(str_replace($this->apiKey, '[redacted]', $value)) ?? '';
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->safeString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized[is_string($key) ? $this->safeString($key) : $key] = $this->sanitize($item);
        }

        return $sanitized;
    }

    private function writeJson(string $path, mixed $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json."\n", LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo guardar la evidencia.');
        }
    }

    private function reservationPath(): string
    {
        return $this->fixtureDirectory.'/'.$this->fixtureName.'.reserved';
    }

    private function submitPath(): string
    {
        return $this->fixtureDirectory.'/'.$this->fixtureName.'-submit.json';
    }

    private function jobPath(): string
    {
        return $this->fixtureDirectory.'/'.$this->fixtureName.'-job.json';
    }

    private function resultPath(): string
    {
        return $this->fixtureDirectory.'/'.$this->fixtureName.'-result.json';
    }
}
