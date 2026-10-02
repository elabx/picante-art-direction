<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Generation\GenerationStateMachine;
use App\Services\Media\DownloadException;
use App\Services\Media\InvalidImageException;
use App\Services\Media\ResultDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DownloadOutputJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 85; // under Laravel Cloud's 90 s Flex managed-queue limit

    public function __construct(public int $outputId) {}

    public function handle(ResultDownloader $downloader): void
    {
        $state = app(GenerationStateMachine::class);
        $claim = $state->claimOutput($this->outputId);
        if ($claim === null) {
            return;
        }
        try {
            $image = $downloader->download($claim->source_url);
        } catch (InvalidImageException) {
            $state->outputInvalid($claim);

            return;
        } catch (DownloadException) {
            $state->outputFailed($claim);

            return;
        }
        $generation = $claim->generation;
        $campaign = Campaign::withTrashed()->find($generation->campaign_id);
        $path = "{$campaign->brand_id}/{$campaign->id}/{$generation->id}/{$claim->id}-{$claim->claim_version}.{$image['ext']}";
        try {
            if (! Storage::disk('pieces')->put($path, $image['bytes'])) {
                $state->outputFailed($claim);

                return;
            }
        } catch (Throwable) {
            $state->outputFailed($claim);

            return;
        }
        try {
            $stored = $state->outputStored($claim, $image, $path);
        } catch (Throwable) {
            $this->removeUnclaimedObject($path);
            $state->outputFailed($claim);

            return;
        }
        if (! $stored) {
            $this->removeUnclaimedObject($path);
        }
    }

    private function removeUnclaimedObject(string $path): void
    {
        try {
            Storage::disk('pieces')->delete($path);
        } catch (Throwable) {
            // Only this claim's unreferenced object is eligible for cleanup.
        }
    }
}
