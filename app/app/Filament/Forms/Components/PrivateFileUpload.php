<?php

namespace App\Filament\Forms\Components;

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\InputUpload;
use App\Services\Media\SignedUrlProvider;
use App\Support\Media;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class PrivateFileUpload extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->visibility('private')->preventFilePathTampering();
    }

    /** @return list<string> */
    public function getOriginalFilePaths(): array
    {
        $record = $this->getRecord();
        if (! $record instanceof Model || ! $record->exists) {
            return [];
        }

        return array_values(array_filter(
            Arr::wrap($record->fresh()?->getAttribute($this->getName())),
            static fn (mixed $path): bool => is_string($path) && filled($path),
        ));
    }

    public function isFilePathAuthorized(string $file, ?array $originalPaths = null): bool
    {
        return self::isCanonicalPath($file) && parent::isFilePathAuthorized($file, $originalPaths);
    }

    public static function isCanonicalPath(string $file): bool
    {
        return $file !== '' && ! str_contains($file, '\\') && preg_match('/\p{C}/u', $file) === 0
            && count(array_intersect(explode('/', $file), ['', '.', '..'])) === 0;
    }

    /**
     * @param  string|array<string,string>|null  $storedFileNames
     * @return array{name:string,size:int,type:?string,url:?string}|null
     */
    public function getUploadedFile(string $file, string|array|null $storedFileNames): ?array
    {
        if (! $this->isFilePathAuthorized($file)) {
            return null;
        }

        try {
            $url = $this->finalizedMediaUrl($file);

            return [
                'name' => ($this->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                'size' => $this->shouldFetchFileInformation() ? $this->getDisk()->size($file) : 0,
                'type' => $this->shouldFetchFileInformation() ? $this->getDisk()->mimeType($file) : null,
                'url' => Str::sanitizeUrl($url ?? app(SignedUrlProvider::class)->url($this->getDiskName(), $file, Media::ttl())),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function finalizedMediaUrl(string $file): ?string
    {
        $record = $this->getRecord()?->fresh();

        if ($record instanceof Campaign && $this->getName() === 'cover_path' && $record->cover_path === $file && Route::has('media.cover')) {
            return route('media.cover', [$record, 'filename' => self::urlFileName($file)]);
        }

        if ($record instanceof Brand && $this->getName() === 'logo_path' && $record->logo_path === $file && Route::has('media.logo')) {
            return route('media.logo', [$record, 'filename' => self::urlFileName($file)]);
        }

        $upload = InputUpload::query()->where('storage_path', $file)->first();

        if ($upload !== null && Route::has('media.upload')) {
            return route('media.upload', [$upload, 'filename' => self::urlFileName($file)]);
        }

        return null;
    }

    /**
     * The uploader names images after the last URL segment, so finalized
     * previews end with the stored file name instead of the record ID.
     */
    private static function urlFileName(string $file): ?string
    {
        $name = basename($file);

        return preg_match('/\A[A-Za-z0-9._-]+\z/', $name) === 1 ? $name : null;
    }
}
