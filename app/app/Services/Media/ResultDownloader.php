<?php

namespace App\Services\Media;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ResultDownloader
{
    private const CHUNK_BYTES = 1024 * 1024;

    public function __construct(private readonly ImageInspector $inspector) {}

    /** @return array{bytes: string, mime: string, width: int, height: int, ext: string} */
    public function download(string $url): array
    {
        if (! $this->isPermittedUrl($url)) {
            throw new DownloadException('URL de resultado no permitida.');
        }

        $maxBytes = (int) config('media.max_result_bytes');

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'stream' => true,
            ])
                ->connectTimeout(5)
                ->timeout(60)
                ->get($url);
        } catch (Throwable) {
            throw new DownloadException('No se pudo descargar el resultado.');
        }

        if ($response->redirect() || ! $response->successful()) {
            $this->closeResponse($response);

            throw new DownloadException("No se pudo descargar el resultado (HTTP {$response->status()}).");
        }

        if ((int) $response->header('Content-Length') > $maxBytes) {
            $this->closeResponse($response);

            throw new DownloadException('El resultado supera el tamaño máximo permitido.');
        }

        try {
            $bytes = $this->readBody($response, $maxBytes);
        } catch (DownloadException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DownloadException('No se pudo descargar el resultado.');
        }

        return ['bytes' => $bytes] + $this->inspector->inspect($bytes);
    }

    private function isPermittedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function closeResponse(Response $response): void
    {
        try {
            $response->toPsrResponse()->getBody()->close();
        } catch (Throwable) {
        }
    }

    private function readBody(Response $response, int $maxBytes): string
    {
        $body = $response->toPsrResponse()->getBody();
        $memory = fopen('php://memory', 'w+b');
        $total = 0;

        if ($memory === false) {
            $body->close();

            throw new DownloadException('No se pudo descargar el resultado.');
        }

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                $total += strlen($chunk);

                if ($total > $maxBytes) {
                    throw new DownloadException('El resultado supera el tamaño máximo permitido.');
                }

                if (fwrite($memory, $chunk) === false) {
                    throw new DownloadException('No se pudo descargar el resultado.');
                }
            }

            rewind($memory);
            $bytes = stream_get_contents($memory);

            if ($bytes === false) {
                throw new DownloadException('No se pudo descargar el resultado.');
            }

            return $bytes;
        } finally {
            fclose($memory);
            $body->close();
        }
    }
}
