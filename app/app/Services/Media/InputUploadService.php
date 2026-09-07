<?php

namespace App\Services\Media;

use App\Models\Brand;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InputUploadService
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly ImageInspector $imageInspector) {}

    public function finalize(string $temporaryPath, Brand $brand, User $user): InputUpload
    {
        $this->ensureTemporaryPathIsValid($temporaryPath);

        $disk = Storage::disk('inputs');

        try {
            if ($disk->size($temporaryPath) > self::MAX_BYTES) {
                $this->throwInvalidImage();
            }

            $bytes = $disk->get($temporaryPath);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->throwUnreadableTemporaryUpload();
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $this->throwInvalidImage();
        }

        try {
            $image = $this->imageInspector->inspect($bytes);
        } catch (InvalidImageException) {
            $this->throwInvalidImage();
        }

        $storagePath = $brand->id.'/'.Str::uuid().'.'.$image['ext'];

        try {
            if (! $disk->put($storagePath, $bytes)) {
                $this->throwUnableToFinalizeUpload();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->throwUnableToFinalizeUpload();
        }

        $upload = new InputUpload;

        try {
            $upload->forceFill([
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'storage_path' => $storagePath,
                'mime_type' => $image['mime'],
                'bytes' => strlen($bytes),
                'width' => $image['width'],
                'height' => $image['height'],
                'finalized_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            try {
                $disk->delete($storagePath);
            } catch (Throwable) {
                // The database failure is the operation's primary error.
            }

            throw $exception;
        }

        try {
            if (! $disk->delete($temporaryPath)) {
                $this->throwUnableToFinalizeUpload();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->throwUnableToFinalizeUpload();
        }

        return $upload;
    }

    public function authorize(int $uploadId, Brand $brand, User $user): InputUpload
    {
        $upload = InputUpload::query()
            ->whereKey($uploadId)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();

        if ($upload === null) {
            throw new AuthorizationException;
        }

        return $upload;
    }

    public function authorizeForPipeline(int $uploadId, Pipeline $pipeline): InputUpload
    {
        $upload = $pipeline->inputUploads()->whereKey($uploadId)->first();

        if ($upload === null) {
            throw new AuthorizationException;
        }

        return $upload;
    }

    public function bytes(InputUpload $upload): string
    {
        return Storage::disk('inputs')->get($upload->storage_path);
    }

    public function dataUrl(InputUpload $upload): string
    {
        return "data:{$upload->mime_type};base64,".base64_encode($this->bytes($upload));
    }

    private function ensureTemporaryPathIsValid(string $temporaryPath): void
    {
        $segments = explode('/', $temporaryPath);

        if (! str_starts_with($temporaryPath, 'tmp/')
            || str_contains($temporaryPath, '\\')
            || str_contains($temporaryPath, "\0")
            || count($segments) < 2
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)) {
            throw ValidationException::withMessages(['temporary_path' => 'Ruta de archivo no válida.']);
        }
    }

    private function throwInvalidImage(): never
    {
        throw ValidationException::withMessages(['image' => 'La imagen debe ser JPEG, PNG o WebP de hasta 20 MB.']);
    }

    private function throwUnreadableTemporaryUpload(): never
    {
        throw ValidationException::withMessages(['temporary_path' => 'No se pudo leer el archivo temporal.']);
    }

    private function throwUnableToFinalizeUpload(): never
    {
        throw ValidationException::withMessages(['temporary_path' => 'No se pudo finalizar el archivo temporal.']);
    }
}
