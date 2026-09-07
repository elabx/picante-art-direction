<?php

use App\Models\Brand;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Media\InputUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('finalizes a temporary upload into an owned immutable object', function (): void {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('tmp/x.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();

    $upload = app(InputUploadService::class)->finalize('tmp/x.png', $brand, $user);

    expect($upload->brand_id)->toBe($brand->id)
        ->and($upload->user_id)->toBe($user->id)
        ->and($upload->width)->toBe(4)
        ->and($upload->height)->toBe(3)
        ->and($upload->mime_type)->toBe('image/png')
        ->and($upload->finalized_at)->not->toBeNull()
        ->and(Storage::disk('inputs')->exists($upload->storage_path))->toBeTrue()
        ->and(Storage::disk('inputs')->exists('tmp/x.png'))->toBeFalse()
        ->and(str_starts_with($upload->storage_path, "{$brand->id}/"))->toBeTrue();
});

it('rejects traversal and paths outside the temporary upload directory', function (): void {
    Storage::fake('inputs');
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $service = app(InputUploadService::class);

    expect(fn (): InputUpload => $service->finalize('../secret.png', $brand, $user))
        ->toThrow(ValidationException::class)
        ->and(fn (): InputUpload => $service->finalize('tmp/../secret.png', $brand, $user))
        ->toThrow(ValidationException::class)
        ->and(fn (): InputUpload => $service->finalize('tmp\\secret.png', $brand, $user))
        ->toThrow(ValidationException::class)
        ->and(fn (): InputUpload => $service->finalize('5/other.png', $brand, $user))
        ->toThrow(ValidationException::class);
});

it('keeps a temporary upload when image validation fails', function (): void {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('tmp/not-an-image.txt', 'not an image');
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();

    expect(fn (): InputUpload => app(InputUploadService::class)->finalize('tmp/not-an-image.txt', $brand, $user))
        ->toThrow(ValidationException::class, 'La imagen debe ser JPEG, PNG o WebP de hasta 20 MB.');

    expect(Storage::disk('inputs')->exists('tmp/not-an-image.txt'))->toBeTrue()
        ->and(InputUpload::query()->count())->toBe(0);
});

it('rejects image bytes above twenty mebibytes without finalizing them', function (): void {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('tmp/large.png', str_repeat('x', 20 * 1024 * 1024 + 1));
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();

    expect(fn (): InputUpload => app(InputUploadService::class)->finalize('tmp/large.png', $brand, $user))
        ->toThrow(ValidationException::class, 'La imagen debe ser JPEG, PNG o WebP de hasta 20 MB.');

    expect(Storage::disk('inputs')->exists('tmp/large.png'))->toBeTrue()
        ->and(InputUpload::query()->count())->toBe(0);
});

it('authorizes only uploads owned by the current brand and user', function (): void {
    $brand = Brand::factory()->create();
    $user = User::factory()->editor()->create();
    $foreign = InputUpload::factory()->create();
    $colleague = User::factory()->editor()->create();
    $brand->users()->attach($colleague);
    $theirs = InputUpload::factory()->create(['brand_id' => $brand->id, 'user_id' => $colleague->id]);
    $owned = InputUpload::factory()->create(['brand_id' => $brand->id, 'user_id' => $user->id]);
    $service = app(InputUploadService::class);

    expect(fn (): InputUpload => $service->authorize($foreign->id, $brand, $user))->toThrow(AuthorizationException::class)
        ->and(fn (): InputUpload => $service->authorize($theirs->id, $brand, $user))->toThrow(AuthorizationException::class)
        ->and($service->authorize($owned->id, $brand, $user)->id)->toBe($owned->id);
});

it('authorizes a fixed image only when it is explicitly linked to the pipeline', function (): void {
    $pipeline = Pipeline::factory()->create();
    $linked = InputUpload::factory()->create();
    $unlinked = InputUpload::factory()->create();
    $pipeline->inputUploads()->attach($linked);
    $service = app(InputUploadService::class);

    expect($service->authorizeForPipeline($linked->id, $pipeline)->id)->toBe($linked->id)
        ->and(fn (): InputUpload => $service->authorizeForPipeline($unlinked->id, $pipeline))
        ->toThrow(AuthorizationException::class);
});

it('returns owned upload bytes and a MIME data URL', function (): void {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('1/example.png', 'image bytes');
    $upload = InputUpload::factory()->create([
        'storage_path' => '1/example.png',
        'mime_type' => 'image/png',
    ]);
    $service = app(InputUploadService::class);

    expect($service->bytes($upload))->toBe('image bytes')
        ->and($service->dataUrl($upload))->toBe('data:image/png;base64,'.base64_encode('image bytes'));
});
