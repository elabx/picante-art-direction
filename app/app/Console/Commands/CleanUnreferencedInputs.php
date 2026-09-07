<?php

namespace App\Console\Commands;

use App\Models\InputUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CleanUnreferencedInputs extends Command
{
    protected $signature = 'media:clean-inputs';

    protected $description = 'Elimina imágenes finalizadas sin referencias tras un período de gracia';

    public function handle(): int
    {
        $cutoff = now()->subHours(24);
        $graceCutoff = now()->subMinutes(10);
        $failed = false;

        InputUpload::query()->where('cleanup_marked_at', '<', $graceCutoff)->lazyById()->each(function (InputUpload $candidate) use ($cutoff, $graceCutoff, &$failed): void {
            $path = DB::transaction(function () use ($candidate, $cutoff, $graceCutoff): ?string {
                $upload = InputUpload::query()->lockForUpdate()->find($candidate->id);
                if ($upload === null || $upload->cleanup_marked_at === null || $upload->cleanup_marked_at->gte($graceCutoff)) {
                    return null;
                }
                if ($this->isReferenced($upload) || $upload->finalized_at === null || $upload->finalized_at->gte($cutoff)) {
                    $upload->forceFill(['cleanup_marked_at' => null])->save();

                    return null;
                }
                $upload->delete();

                return $upload->storage_path;
            });

            if ($path === null) {
                return;
            }
            try {
                $deleted = Storage::disk('inputs')->delete($path);
            } catch (Throwable) {
                $deleted = false;
            }
            if (! $deleted) {
                $failed = true;
                $this->warn("No se pudo eliminar el objeto de la carga {$candidate->id}; el registro ya se eliminó.");
            }
        });

        InputUpload::query()->whereNull('cleanup_marked_at')->where('finalized_at', '<', $cutoff)->lazyById()->each(function (InputUpload $candidate) use ($cutoff): void {
            DB::transaction(function () use ($candidate, $cutoff): void {
                $upload = InputUpload::query()->lockForUpdate()->find($candidate->id);
                if ($upload !== null && $upload->cleanup_marked_at === null && $upload->finalized_at?->lt($cutoff) && ! $this->isReferenced($upload)) {
                    $upload->forceFill(['cleanup_marked_at' => now()])->save();
                }
            });
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function isReferenced(InputUpload $upload): bool
    {
        // Current reads are required after waiting for the upload lock under MySQL's repeatable-read isolation.
        return DB::table('generation_inputs')->where('input_upload_id', $upload->id)->lockForUpdate()->first() !== null
            || DB::table('pipeline_inputs')->where('input_upload_id', $upload->id)->lockForUpdate()->first() !== null;
    }
}
