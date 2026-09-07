<?php

namespace App\Services\Generation;

use App\Models\Generation;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Services\Media\InputUploadService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SnapshotExpander
{
    public function __construct(private readonly InputUploadService $inputUploads) {}

    /**
     * @return array<string, mixed>
     */
    public function expand(Generation $generation): array
    {
        $inputs = $generation->execution_snapshot['inputs'] ?? [];

        if (! is_array($inputs)) {
            throw new RuntimeException('No pudimos preparar los archivos solicitados.');
        }

        $expanded = [];
        try {
            foreach ($inputs as $name => $value) {
                $expanded[$name] = $this->expandValue($generation, $value);
            }

            $payload = json_encode($expanded, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('No pudimos preparar los archivos solicitados.');
        }

        if (strlen($payload) > config('media.max_request_bytes')) {
            throw new RuntimeException('La solicitud supera el tamaño máximo permitido.');
        }

        return $expanded;
    }

    private function expandValue(Generation $generation, mixed $value): mixed
    {
        if (! is_array($value) || count($value) !== 1) {
            return $value;
        }

        if (array_key_exists('__upload', $value)) {
            $uploadId = $value['__upload'];
            if (! is_int($uploadId) || $uploadId < 1) {
                throw new RuntimeException('No pudimos preparar los archivos solicitados.');
            }

            $upload = $generation->inputUploads()->whereKey($uploadId)->first();
            if (! $upload instanceof InputUpload) {
                throw new RuntimeException('No pudimos preparar los archivos solicitados.');
            }

            return $this->inputUploads->dataUrl($upload);
        }

        if (array_key_exists('__piece', $value)) {
            $pieceId = $value['__piece'];
            if (! is_int($pieceId) || $pieceId < 1) {
                throw new RuntimeException('No pudimos preparar los archivos solicitados.');
            }

            $piece = Piece::query()->find($pieceId);
            if (! $piece instanceof Piece) {
                throw new RuntimeException('No pudimos preparar los archivos solicitados.');
            }

            return "data:{$piece->mime_type};base64,".base64_encode(Storage::disk('pieces')->get($piece->storage_path));
        }

        return $value;
    }
}
