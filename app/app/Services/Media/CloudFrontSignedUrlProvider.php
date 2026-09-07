<?php

namespace App\Services\Media;

use App\Support\Media;
use Aws\CloudFront\UrlSigner;
use DateTimeInterface;
use InvalidArgumentException;

final class CloudFrontSignedUrlProvider implements SignedUrlProvider
{
    public function url(string $disk, string $path, DateTimeInterface $expiresAt, bool $download = false, ?string $filename = null): string
    {
        $configuration = $this->configuration();
        $diskRoot = config("filesystems.disks.{$disk}.root");

        if (! is_string($diskRoot) || $diskRoot === '') {
            throw new InvalidArgumentException('The storage disk root must be configured for CloudFront signed URLs.');
        }

        $url = 'https://'.rtrim($configuration['domain'], '/').'/'.$this->encodePath(trim($diskRoot, '/')).'/'.$this->encodePath($path);

        if ($download) {
            $url .= '?'.http_build_query([
                'response-content-disposition' => Media::contentDisposition($filename),
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return (new UrlSigner($configuration['key_pair_id'], $configuration['private_key_path']))
            ->getSignedUrl($url, $expiresAt->getTimestamp());
    }

    /** @return array{domain: string, key_pair_id: string, private_key_path: string} */
    private function configuration(): array
    {
        $configuration = config('media.cloudfront');

        if (! is_array($configuration)
            || ! is_string($configuration['domain'] ?? null)
            || $configuration['domain'] === ''
            || ! is_string($configuration['key_pair_id'] ?? null)
            || $configuration['key_pair_id'] === ''
            || ! is_string($configuration['private_key_path'] ?? null)
            || $configuration['private_key_path'] === '') {
            throw new InvalidArgumentException('CloudFront signed URL configuration is incomplete.');
        }

        return [
            'domain' => $configuration['domain'],
            'key_pair_id' => $configuration['key_pair_id'],
            'private_key_path' => $configuration['private_key_path'],
        ];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
