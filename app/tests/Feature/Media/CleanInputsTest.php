<?php

use App\Enums\InputType;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Generation\CreateGeneration;
use App\Services\Generation\RestartGeneration;
use App\Services\Pipelines\PipelineFieldConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('inputs');
    $this->travelTo(now()->startOfSecond());
});

function oldInput(array $attributes = []): InputUpload
{
    $upload = InputUpload::factory()->create($attributes + [
        'created_at' => now()->subDays(2),
        'finalized_at' => now()->subDays(2),
    ]);
    Storage::disk('inputs')->put($upload->storage_path, 'image');

    return $upload;
}

it('marks old finalized inputs and only deletes them on a later run after ten minutes', function (): void {
    $upload = oldInput();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($upload->fresh()->cleanup_marked_at?->equalTo(now()))->toBeTrue();
    Storage::disk('inputs')->assertExists($upload->storage_path);

    $this->travel(10)->minutes();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($upload->fresh())->not->toBeNull();
    $this->travel(1)->seconds();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($upload->fresh())->toBeNull();
    Storage::disk('inputs')->assertMissing($upload->storage_path);
});

it('preserves referenced inputs through both passes', function (string $reference): void {
    $upload = oldInput();
    $owner = $reference === 'generation' ? Generation::factory()->create() : Pipeline::factory()->create();
    $owner->inputUploads()->attach($upload);
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $this->travel(11)->minutes();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($upload->fresh()->cleanup_marked_at)->toBeNull();
    Storage::disk('inputs')->assertExists($upload->storage_path);
})->with(['generation', 'pipeline']);

it('unmarks inputs referenced between passes even when the reference was inserted directly', function (string $reference): void {
    $upload = oldInput();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $owner = $reference === 'generation' ? Generation::factory()->create() : Pipeline::factory()->create();
    $owner->inputUploads()->attach($upload);
    $this->travel(11)->minutes();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($upload->fresh()->cleanup_marked_at)->toBeNull();
    Storage::disk('inputs')->assertExists($upload->storage_path);
})->with(['generation', 'pipeline']);

it('leaves recent and unfinished inputs and unrelated objects alone', function (): void {
    $recent = oldInput(['finalized_at' => now()->subHours(24)]);
    $unfinished = oldInput(['finalized_at' => null]);
    Storage::disk('inputs')->put('tmp/untracked.png', 'temporary');
    Storage::disk('inputs')->put('logos/untracked.png', 'logo');
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($recent->fresh()->cleanup_marked_at)->toBeNull()
        ->and($unfinished->fresh()->cleanup_marked_at)->toBeNull();
    Storage::disk('inputs')->assertExists([$recent->storage_path, $unfinished->storage_path, 'tmp/untracked.png', 'logos/untracked.png']);
});

it('removes the database row before storage and safely reports an orphan on storage failure', function (bool $throws): void {
    $upload = oldInput();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $this->travel(11)->minutes();
    $initialLevel = DB::transactionLevel();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('delete')->once()->with($upload->storage_path)->andReturnUsing(function () use ($upload, $initialLevel, $throws): bool {
        expect($upload->fresh())->toBeNull()
            ->and(DB::transactionLevel())->toBe($initialLevel);
        if ($throws) {
            throw new RuntimeException('secret https://storage.test/object?X-Amz-Signature=credential');
        }

        return false;
    });
    Storage::shouldReceive('disk')->with('inputs')->andReturn($disk);
    Log::spy();
    expect(Artisan::call('media:clean-inputs'))->toBe(1);
    expect(Artisan::output())->toContain('No se pudo eliminar')->not->toContain('credential', 'secret', 'https://');
    Log::shouldNotHaveReceived('error');
    expect($upload->fresh())->toBeNull();
})->with([false, true]);

it('does not remove storage when deleting the database row fails', function (): void {
    $upload = oldInput();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $this->travel(11)->minutes();
    InputUpload::deleting(function (): never {
        throw new RuntimeException('database failure');
    });
    try {
        expect(fn () => Artisan::call('media:clean-inputs'))->toThrow(RuntimeException::class);
        expect($upload->fresh())->not->toBeNull();
        Storage::disk('inputs')->assertExists($upload->storage_path);
    } finally {
        InputUpload::flushEventListeners();
    }
});

/** @return array{User, Campaign, Pipeline, InputUpload} */
function cleanupGenerationContext(): array
{
    Queue::fake();
    [$user, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create(['name' => 'reference', 'input_type' => InputType::Image]);
    $upload = oldInput(['brand_id' => $brand->id, 'user_id' => $user->id]);

    return [$user, $campaign, $pipeline, $upload];
}

it('clears the cleanup mark while creating a generation reference', function (): void {
    [$user, $campaign, $pipeline, $upload] = cleanupGenerationContext();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $generation = app(CreateGeneration::class)->series($user, $campaign, $pipeline,
        ['describe_la_escena' => 'taller', 'reference' => $upload->id], (string) Str::uuid(), 3);
    expect($upload->fresh()->cleanup_marked_at)->toBeNull()
        ->and($generation->inputUploads()->whereKey($upload->id)->exists())->toBeTrue();
    $this->travel(11)->minutes();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    Storage::disk('inputs')->assertExists($upload->storage_path);
});

it('rejects a reference whose upload disappears after authorization before linking', function (): void {
    [$user, $campaign, $pipeline, $upload] = cleanupGenerationContext();
    $removed = false;
    DB::listen(function (QueryExecuted $query) use ($upload, &$removed): void {
        if (! $removed && str_starts_with($query->sql, 'select * from `input_uploads`') && in_array($upload->id, $query->bindings, true)) {
            $removed = true;
            InputUpload::query()->whereKey($upload->id)->delete();
        }
    });
    expect(fn () => app(CreateGeneration::class)->series($user, $campaign, $pipeline,
        ['describe_la_escena' => 'taller', 'reference' => $upload->id], (string) Str::uuid(), 3))->toThrow(AuthorizationException::class);
    expect($removed)->toBeTrue()->and(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rechecks a cleanup candidate after a generation links it before the row lock', function (): void {
    [$user, $campaign, $pipeline, $upload] = cleanupGenerationContext();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $this->travel(11)->minutes();
    $linked = false;
    DB::listen(function (QueryExecuted $query) use ($user, $campaign, $pipeline, $upload, &$linked): void {
        if (! $linked && str_starts_with($query->sql, 'select * from `input_uploads`') && str_contains($query->sql, '`cleanup_marked_at` <') && ! str_contains($query->sql, 'for update')) {
            $linked = true;
            app(CreateGeneration::class)->series($user, $campaign, $pipeline,
                ['describe_la_escena' => 'taller', 'reference' => $upload->id], (string) Str::uuid(), 3);
        }
    });
    $this->artisan('media:clean-inputs')->assertSuccessful();
    expect($linked)->toBeTrue()->and($upload->fresh()->cleanup_marked_at)->toBeNull();
    Storage::disk('inputs')->assertExists($upload->storage_path);
});

it('clears marks and rechecks surviving uploads when creating a confirmed replacement', function (bool $removed): void {
    [$user, $campaign, $pipeline, $upload] = cleanupGenerationContext();
    $this->artisan('media:clean-inputs')->assertSuccessful();
    $original = Generation::factory()->for($campaign)->for($pipeline)->for($user)->create([
        'status' => 'failed', 'failure_reason' => 'submission_unknown', 'retryable' => true,
        'execution_snapshot' => snapshot(['inputs' => ['reference' => ['__upload' => $upload->id]]]),
    ]);
    $authorizations = 0;
    if ($removed) {
        DB::listen(function (QueryExecuted $query) use ($upload, &$authorizations): void {
            if (str_starts_with($query->sql, 'select * from `input_uploads`') && str_contains($query->sql, '`user_id` =') && ++$authorizations === 2) {
                InputUpload::query()->whereKey($upload->id)->delete();
            }
        });
        expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $original, (string) Str::uuid()))->toThrow(AuthorizationException::class);
        expect(Generation::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    } else {
        $replacement = app(RestartGeneration::class)->confirmRestart($user, $original, (string) Str::uuid());
        expect($upload->fresh()->cleanup_marked_at)->toBeNull()
            ->and($replacement->inputUploads()->whereKey($upload->id)->exists())->toBeTrue();
    }
})->with([false, true]);

it('retains finalized fixed images under the field linking transaction', function (bool $removed): void {
    $user = User::factory()->artDirector()->create();
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $field = PipelineField::factory()->for($pipeline)->create(['input_type' => 'image', 'visibility' => 'hidden']);
    Storage::disk('inputs')->put('tmp/fixed.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $uploadId = null;
    InputUpload::created(function (InputUpload $upload) use (&$uploadId, $removed): void {
        $uploadId = $upload->id;
        if ($removed) {
            $upload->delete();
        } else {
            $upload->forceFill(['cleanup_marked_at' => now()->subMinutes(11)])->save();
        }
    });
    try {
        $save = fn () => app(PipelineFieldConfiguration::class)->save($pipeline, $field,
            ['has_fixed_value' => true, 'fixed_value' => 'tmp/fixed.png'], $user);
        if ($removed) {
            expect($save)->toThrow(AuthorizationException::class);
            expect($field->fresh()->has_fixed_value)->toBeFalse()
                ->and($pipeline->inputUploads()->count())->toBe(0);
        } else {
            $save();
            expect(InputUpload::findOrFail($uploadId)->cleanup_marked_at)->toBeNull()
                ->and($pipeline->inputUploads()->whereKey($uploadId)->exists())->toBeTrue();
        }
    } finally {
        InputUpload::flushEventListeners();
    }
})->with([false, true]);
