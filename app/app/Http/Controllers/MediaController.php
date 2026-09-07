<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Media\InputUploadService;
use App\Services\Media\SignedUrlProvider;
use App\Support\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaController
{
    public function __construct(private readonly SignedUrlProvider $signedUrls) {}

    public function piece(Piece $piece, Request $request): RedirectResponse
    {
        $campaign = Campaign::query()->withTrashed()->findOrFail($piece->campaign_id);
        $this->authorizeBrand($campaign->brand_id);

        return $this->redirect(
            'pieces',
            $piece->storage_path,
            $request,
            "pieza-{$piece->id}-{$piece->width}x{$piece->height}.{$this->extension($piece->storage_path)}",
        );
    }

    public function upload(InputUpload $upload, Request $request): RedirectResponse
    {
        $user = $this->user();

        if ($user->isArtDirector()) {
            abort_unless($this->isFixedUpload($upload), 403);
        } elseif ($upload->user_id === $user->id) {
            $brand = Brand::query()->findOrFail($upload->brand_id);
            $this->authorizeBrand($brand->id);
            app(InputUploadService::class)->authorize($upload->id, $brand, $user);
        } else {
            abort_unless($this->isFixedUpload($upload, $user), 403);
        }

        return $this->redirect(
            'inputs',
            $upload->storage_path,
            $request,
            "archivo-{$upload->id}-{$upload->width}x{$upload->height}.{$this->extension($upload->storage_path)}",
        );
    }

    public function cover(Campaign $campaign, Request $request): RedirectResponse
    {
        $this->authorizeBrand($campaign->brand_id);

        return $this->redirect('pieces', $campaign->cover_path, $request, "portada-{$campaign->id}.{$this->extension($campaign->cover_path)}");
    }

    public function logo(Brand $brand, Request $request): RedirectResponse
    {
        $this->authorizeBrand($brand->id);

        return $this->redirect('pieces', $brand->logo_path, $request, "logotipo-{$brand->id}.{$this->extension($brand->logo_path)}");
    }

    private function authorizeBrand(int $brandId): void
    {
        $user = $this->user();

        abort_unless($user->isArtDirector() || $user->brands()->whereKey($brandId)->exists(), 403);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user->fresh() ?? abort(403);
    }

    private function isFixedUpload(InputUpload $upload, ?User $user = null): bool
    {
        return Pipeline::query()
            ->whereHas('inputUploads', fn ($query) => $query->whereKey($upload->id))
            ->whereHas('campaign', fn ($query) => $query
                ->where('brand_id', $upload->brand_id)
                ->when($user, fn ($query) => $query->whereHas('brand.users', fn ($query) => $query->whereKey($user->id))))
            ->exists();
    }

    private function redirect(string $disk, ?string $path, Request $request, string $filename): RedirectResponse
    {
        abort_if(blank($path), 404);

        return redirect()
            ->away($this->signedUrls->url($disk, $path, Media::ttl(), $request->boolean('download'), $filename))
            ->header('Cache-Control', 'no-store, private');
    }

    private function extension(?string $path): string
    {
        return Str::lower(pathinfo((string) $path, PATHINFO_EXTENSION));
    }
}
