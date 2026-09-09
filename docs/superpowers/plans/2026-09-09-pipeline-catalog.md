# Pipeline Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn per-campaign pipelines into a studio-wide catalog of Krea apps that campaigns reference, plus a seeder that creates catalog entries from `.env` so local setups never retype version IDs.

**Architecture:** `pipelines` loses `campaign_id`/`sort_order`/`is_active` and gains `is_ready`; a new `campaign_pipeline` pivot carries the assignment and order. Field configuration, schema sync, readiness, and the execution snapshot keep working on the same `Pipeline` model, so the domain layer changes only where it looked up pipelines by campaign. The admin panel gets a top-level "Catálogo de apps" resource and the campaign page gets an assign/remove/reorder relation manager. A `PipelineCatalogSeeder` reads IDs from `config('media.krea.test_apps')`.

**Tech Stack:** PHP 8.5, Laravel 12, Filament v5.7.8 (`Filament\Schemas\Schema`, actions use `->schema([...])`), Livewire 4, Pest, MySQL 8.0 through ddev.

**Spec:** `docs/superpowers/specs/2026-09-09-pipeline-catalog-design.md` — read it first; the plan argues from it.

## Global Constraints

- **All commands run through ddev from the repo root:** `ddev pest …`, `ddev pint …`, `ddev artisan …`. The Laravel app lives in `app/`; file paths below are relative to `app/`.
- **All UI text is Spanish.** Copy strings are given verbatim; do not paraphrase.
- **Rewrite the original migrations; no data migration.** After Task 1 the local database is reset with `ddev artisan migrate:fresh --seed`. Never add "alter" migrations for this change.
- **Schema sync uses the studio Krea key** (`media.krea.key`); generations keep resolving the key per brand and pinning `credential_source` in the snapshot. Do not touch `CreateGeneration::snapshot()`.
- **The seeder never calls Krea.** No engine call, no fixture read.
- **Kind rule stays:** several generators, at most one editor, at most one upscaler per campaign. Enforced at assignment.
- **Provider error messages and existing Spanish messages stay verbatim** unless a task replaces them explicitly.
- Run `ddev pint --dirty --format agent` after touching PHP files. Commit after every task with a conventional-commit message ending in the attribution trailer used in this repo.
- Filament API note: actions define their form with `->schema([...])` (Filament 5). `CreateAction::make()->schema([...])` is valid.
- Tests: every test boots Laravel with `RefreshDatabase`. Use factories and the helpers in `tests/Pest.php`. Run the narrowest set first, then the full suite in Task 10.

---

### Task 1: Schema, models, factories, and test helpers

**Files:**
- Modify: `database/migrations/2026_09_07_154416_create_pipelines_table.php`
- Create: `database/migrations/2026_09_07_154418_create_campaign_pipeline_table.php`
- Modify: `database/migrations/2026_09_07_155146_create_input_uploads_table.php` (brand nullable)
- Modify: `app/Models/Pipeline.php`, `app/Models/Campaign.php`, `app/Models/InputUpload.php`
- Modify: `database/factories/PipelineFactory.php`, `database/factories/InputUploadFactory.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Models/CoreModelsTest.php`

**Interfaces:**
- Produces: `Campaign::pipelines(): BelongsToMany` (pivot `sort_order`, ordered), `Campaign::activeGenerators(): BelongsToMany`, `Campaign::activeEditor(): ?Pipeline`, `Campaign::activeUpscaler(): ?Pipeline`, `Pipeline::campaigns(): BelongsToMany`, `Pipeline::isReady(): bool`, factory states `generator()`, `editor()`, `upscaler()`, `ready()`, `InputUploadFactory::catalog()`, Pest helpers `attachPipeline(Campaign, Pipeline, int = 0): Pipeline` and the updated `readyGenerator(Campaign): Pipeline`.

- [ ] **Step 1: Rewrite the pipelines migration**

Replace the `up()` body of `database/migrations/2026_09_07_154416_create_pipelines_table.php`:

```php
Schema::create('pipelines', function (Blueprint $table) {
    $table->id();
    $table->string('kind', 20);
    $table->string('engine', 20)->default('krea');
    $table->string('provider_ref');
    $table->string('label');
    $table->json('input_schema')->nullable();
    $table->timestamp('schema_fetched_at')->nullable();
    $table->unsignedInteger('config_revision')->default(1);
    $table->json('readiness_errors')->nullable();
    $table->boolean('is_ready')->default(false);
    $table->timestamps();
    $table->unique(['engine', 'provider_ref']);
});
```

- [ ] **Step 2: Create the pivot migration**

`database/migrations/2026_09_07_154418_create_campaign_pipeline_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_pipeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'pipeline_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_pipeline');
    }
};
```

- [ ] **Step 3: Make `input_uploads.brand_id` nullable**

In `database/migrations/2026_09_07_155146_create_input_uploads_table.php` change the brand line to:

```php
$table->foreignId('brand_id')->nullable()->constrained();
```

- [ ] **Step 4: Update the `Pipeline` model**

Replace `app/Models/Pipeline.php` with:

```php
<?php

namespace App\Models;

use App\Enums\PipelineKind;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    protected $fillable = [
        'kind',
        'engine',
        'provider_ref',
        'label',
        'input_schema',
        'schema_fetched_at',
        'config_revision',
        'readiness_errors',
        'is_ready',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PipelineKind::class,
            'input_schema' => 'array',
            'readiness_errors' => 'array',
            'schema_fetched_at' => 'datetime',
            'is_ready' => 'boolean',
        ];
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot('sort_order')->withTimestamps();
    }

    public function fields(): HasMany
    {
        return $this->hasMany(PipelineField::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeFields(): HasMany
    {
        return $this->fields()->where('stale', false);
    }

    public function inputUploads(): BelongsToMany
    {
        return $this->belongsToMany(InputUpload::class, 'pipeline_inputs');
    }

    public function isReady(): bool
    {
        return $this->is_ready;
    }
}
```

- [ ] **Step 5: Update the `Campaign` model relations**

In `app/Models/Campaign.php` replace the `use` line for `HasMany` with both `BelongsToMany` and `HasMany`, and replace `pipelines()`, `activeGenerators()`, `activeEditor()`, `activeUpscaler()`:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function pipelines(): BelongsToMany
{
    return $this->belongsToMany(Pipeline::class)
        ->withPivot('sort_order')
        ->withTimestamps()
        ->orderBy('campaign_pipeline.sort_order')
        ->orderBy('pipelines.id');
}

public function activeGenerators(): BelongsToMany
{
    return $this->pipelines()
        ->where('pipelines.kind', PipelineKind::Generator)
        ->where('pipelines.is_ready', true);
}

public function activeEditor(): ?Pipeline
{
    return $this->pipelines()
        ->where('pipelines.kind', PipelineKind::Editor)
        ->where('pipelines.is_ready', true)
        ->first();
}

public function activeUpscaler(): ?Pipeline
{
    return $this->pipelines()
        ->where('pipelines.kind', PipelineKind::Upscaler)
        ->where('pipelines.is_ready', true)
        ->first();
}
```

`defaultPipeline()` stays as is.

- [ ] **Step 6: Update factories**

`database/factories/PipelineFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\PipelineKind;
use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'generator',
            'engine' => 'krea',
            'provider_ref' => fake()->uuid(),
            'label' => fake()->sentence(3),
            'input_schema' => null,
            'schema_fetched_at' => null,
            'config_revision' => 1,
            'readiness_errors' => null,
            'is_ready' => false,
        ];
    }

    public function generator(): static
    {
        return $this->state(['kind' => PipelineKind::Generator]);
    }

    public function editor(): static
    {
        return $this->state(['kind' => PipelineKind::Editor]);
    }

    public function upscaler(): static
    {
        return $this->state(['kind' => PipelineKind::Upscaler]);
    }

    public function ready(): static
    {
        return $this->state(['is_ready' => true, 'readiness_errors' => []]);
    }
}
```

Add to `database/factories/InputUploadFactory.php`:

```php
public function catalog(): static
{
    return $this->state(['brand_id' => null, 'storage_path' => 'catalog/'.fake()->uuid().'.png']);
}
```

`InputUpload::brand()` stays; a null `brand_id` simply returns null.

- [ ] **Step 7: Update Pest helpers**

In `tests/Pest.php` replace `readyGenerator` and add `attachPipeline`:

```php
function attachPipeline(Campaign $campaign, Pipeline $pipeline, int $sortOrder = 0): Pipeline
{
    $campaign->pipelines()->attach($pipeline->id, ['sort_order' => $sortOrder]);

    return $pipeline;
}

function readyGenerator(Campaign $campaign): Pipeline
{
    $pipeline = Pipeline::factory()->generator()->ready()->create([
        'input_schema' => ['properties' => []],
        'config_revision' => 3,
    ]);

    PipelineField::factory()->for($pipeline)->create([
        'name' => 'describe_la_escena',
        'input_type' => InputType::String,
        'role' => FieldRole::Prompt,
        'required' => true,
    ]);

    attachPipeline($campaign, $pipeline);

    return $pipeline->refresh();
}
```

- [ ] **Step 8: Write the model tests**

In `tests/Feature/Models/CoreModelsTest.php` rewrite the test `links editors to brands and exposes active pipelines by kind` so it builds pipelines with the new factory and pivot, and add one test for the pivot:

```php
it('links editors to brands and exposes ready pipelines by kind through the pivot', function () {
    $campaign = Campaign::factory()->create();
    $generatorB = attachPipeline($campaign, Pipeline::factory()->generator()->ready()->create(['label' => 'B']), 2);
    $generatorA = attachPipeline($campaign, Pipeline::factory()->generator()->ready()->create(['label' => 'A']), 1);
    attachPipeline($campaign, Pipeline::factory()->generator()->create(['label' => 'not ready']), 0);
    $editor = attachPipeline($campaign, Pipeline::factory()->editor()->ready()->create());
    $upscaler = attachPipeline($campaign, Pipeline::factory()->upscaler()->ready()->create());
    Pipeline::factory()->generator()->ready()->create(['label' => 'unassigned']);

    expect($campaign->activeGenerators()->pluck('label')->all())->toBe(['A', 'B'])
        ->and($campaign->activeEditor()?->id)->toBe($editor->id)
        ->and($campaign->activeUpscaler()?->id)->toBe($upscaler->id)
        ->and($campaign->pipelines()->count())->toBe(5)
        ->and($generatorA->campaigns()->pluck('campaigns.id')->all())->toBe([$campaign->id]);
});

it('refuses to delete a catalog app that a campaign still uses', function () {
    $campaign = Campaign::factory()->create();
    $pipeline = attachPipeline($campaign, Pipeline::factory()->create());

    expect(fn () => $pipeline->delete())->toThrow(\Illuminate\Database\QueryException::class);
    expect(Pipeline::query()->whereKey($pipeline->id)->exists())->toBeTrue();
});
```

Keep the other tests in the file; if any builds `Pipeline::factory()->for($campaign)`, switch it to `attachPipeline($campaign, Pipeline::factory()->create([...]))` and replace `'is_active' => true` with `'is_ready' => true`.

- [ ] **Step 9: Run the model tests**

Run: `ddev pest tests/Feature/Models/CoreModelsTest.php`
Expected: PASS. (Other suites will fail until later tasks; that is expected.)

- [ ] **Step 10: Reset the local database**

Run: `ddev artisan migrate:fresh --seed --no-interaction`
Expected: all migrations run; `campaign_pipeline` exists (`ddev mysql -e 'show create table campaign_pipeline\G'`).

- [ ] **Step 11: Commit**

```bash
git add app/database app/app/Models app/tests/Pest.php app/tests/Feature/Models/CoreModelsTest.php
git commit -m "feat(catalog): studio-wide pipelines table and campaign_pipeline pivot"
```

---

### Task 2: Readiness state service

**Files:**
- Modify: `app/Services/Pipelines/PipelineActivation.php`
- Modify: `app/Services/Pipelines/PipelineReadiness.php:114-130`
- Test: `tests/Feature/Pipelines/PipelineActivationTest.php`, `tests/Feature/Pipelines/PipelineReadinessTest.php`

**Interfaces:**
- Produces: `PipelineActivation::markReady(Pipeline): void` (throws `ValidationException` key `pipeline`), `PipelineActivation::markNotReady(Pipeline, array $errors = []): void`, `PipelineActivation::setDefault(Campaign, Pipeline): void`.
- Removes: `activate()`, `deactivate()`.

- [ ] **Step 1: Rewrite the activation tests**

Replace `tests/Feature/Pipelines/PipelineActivationTest.php`:

```php
<?php

use App\Enums\FieldRole;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Services\Pipelines\PipelineActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function configuredPipeline(PipelineKind $kind): Pipeline
{
    $pipeline = Pipeline::factory()->create([
        'kind' => $kind,
        'input_schema' => ['properties' => ['image' => ['type' => 'string'], 'prompt' => ['type' => 'string']]],
    ]);

    if ($kind === PipelineKind::Editor) {
        PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
        PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt]);
    }

    if ($kind === PipelineKind::Upscaler) {
        PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
    }

    return $pipeline;
}

it('refuses to mark a pipeline ready when readiness fails and stores the errors', function (): void {
    $pipeline = Pipeline::factory()->create(['input_schema' => null]);

    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))
        ->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($pipeline->fresh()->readiness_errors)->toBe(['El flujo no publica un esquema de entradas.']);
});

it('marks a configured pipeline ready under a row lock', function (): void {
    $sqls = [];
    DB::listen(function ($query) use (&$sqls): void {
        $sqls[] = $query->sql;
    });
    $pipeline = configuredPipeline(PipelineKind::Editor);

    app(PipelineActivation::class)->markReady($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeTrue()
        ->and($pipeline->fresh()->readiness_errors)->toBe([])
        ->and(collect($sqls)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'for update')))->toBeTrue();
});

it('uses persisted readiness state instead of stale loaded field relations', function (): void {
    $pipeline = configuredPipeline(PipelineKind::Editor)->load('fields');
    $pipeline->fields->firstWhere('role', FieldRole::Prompt)->update(['needs_configuration' => true]);

    expect(fn () => app(PipelineActivation::class)->markReady($pipeline))
        ->toThrow(ValidationException::class);
    expect($pipeline->fresh()->is_ready)->toBeFalse();
});

it('is idempotent when marking ready twice', function (): void {
    $pipeline = configuredPipeline(PipelineKind::Upscaler);
    $activation = app(PipelineActivation::class);

    $activation->markReady($pipeline);
    $activation->markReady($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeTrue();
});

it('marks not ready, stores errors, and clears defaults in every campaign', function (): void {
    $pipeline = configuredPipeline(PipelineKind::Generator);
    app(PipelineActivation::class)->markReady($pipeline);
    $first = Campaign::factory()->create();
    $second = Campaign::factory()->create();
    attachPipeline($first, $pipeline);
    attachPipeline($second, $pipeline);
    $first->update(['default_pipeline_id' => $pipeline->id]);
    $second->update(['default_pipeline_id' => $pipeline->id]);

    app(PipelineActivation::class)->markNotReady($pipeline, ['Campo x: nuevo o modificado; revisa su configuración.']);

    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($pipeline->fresh()->readiness_errors)->toBe(['Campo x: nuevo o modificado; revisa su configuración.'])
        ->and($first->fresh()->default_pipeline_id)->toBeNull()
        ->and($second->fresh()->default_pipeline_id)->toBeNull()
        ->and($first->pipelines()->count())->toBe(1);
});

it('sets a default generator only when it is a ready generator assigned to the campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $generator = attachPipeline($campaign, Pipeline::factory()->generator()->ready()->create());
    $unassigned = Pipeline::factory()->generator()->ready()->create();
    $editor = attachPipeline($campaign, Pipeline::factory()->editor()->ready()->create());
    $notReady = attachPipeline($campaign, Pipeline::factory()->generator()->create());

    app(PipelineActivation::class)->setDefault($campaign, $generator);
    expect($campaign->fresh()->default_pipeline_id)->toBe($generator->id);

    foreach ([$unassigned, $editor, $notReady] as $invalid) {
        expect(fn () => app(PipelineActivation::class)->setDefault($campaign, $invalid))
            ->toThrow(ValidationException::class);
    }
    expect($campaign->fresh()->default_pipeline_id)->toBe($generator->id);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `ddev pest tests/Feature/Pipelines/PipelineActivationTest.php`
Expected: FAIL with "Call to undefined method … markReady".

- [ ] **Step 3: Rewrite `PipelineActivation`**

```php
<?php

namespace App\Services\Pipelines;

use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PipelineActivation
{
    public function __construct(private readonly PipelineReadiness $readiness) {}

    /**
     * @throws ValidationException when the catalog entry is not ready
     */
    public function markReady(Pipeline $pipeline): void
    {
        $errors = DB::transaction(function () use ($pipeline): array {
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());
            $errors = $this->readiness->evaluate($current);
            $current->update(['readiness_errors' => $errors, 'is_ready' => $errors === []]);

            return $errors;
        });

        if ($errors !== []) {
            throw ValidationException::withMessages(['pipeline' => $errors]);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    public function markNotReady(Pipeline $pipeline, array $errors = []): void
    {
        DB::transaction(function () use ($pipeline, $errors): void {
            $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());
            $current->update(['is_ready' => false, 'readiness_errors' => $errors]);
            Campaign::query()->withTrashed()->where('default_pipeline_id', $current->id)->update(['default_pipeline_id' => null]);
        });
    }

    public function setDefault(Campaign $campaign, Pipeline $pipeline): void
    {
        DB::transaction(function () use ($campaign, $pipeline): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentPipeline = $currentCampaign->pipelines()->whereKey($pipeline->getKey())->first();

            if ($currentPipeline === null
                || $currentPipeline->kind !== PipelineKind::Generator
                || ! $currentPipeline->is_ready) {
                throw ValidationException::withMessages([
                    'pipeline' => ['El generador por defecto debe ser un generador listo asignado a esta campaña.'],
                ]);
            }

            $currentCampaign->update(['default_pipeline_id' => $currentPipeline->id]);
        });
    }
}
```

- [ ] **Step 4: Fix the brand check in `PipelineReadiness`**

In `app/Services/Pipelines/PipelineReadiness.php` replace the tail of `isLinkedImageUploadReference` (the `$brandId` lookup and the return) with:

```php
return $pipeline->inputUploads()
    ->whereKey($value['__upload'])
    ->exists();
```

Remove the now unused `$brandId` variable.

- [ ] **Step 5: Update `PipelineReadinessTest`**

In `tests/Feature/Pipelines/PipelineReadinessTest.php` replace every `Pipeline::factory()->for($campaign)` / `->for(Campaign::factory())` with `Pipeline::factory()`, and any fixed-image upload test that created the upload with the campaign's brand now uses `InputUpload::factory()->catalog()->create()` attached through `$pipeline->inputUploads()->attach($upload->id)`. Any assertion that a fixed image from another brand is invalid is deleted: catalog uploads have no brand.

- [ ] **Step 6: Run the tests**

Run: `ddev pest tests/Feature/Pipelines/PipelineActivationTest.php tests/Feature/Pipelines/PipelineReadinessTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/app/Services/Pipelines/PipelineActivation.php app/app/Services/Pipelines/PipelineReadiness.php app/tests/Feature/Pipelines
git commit -m "feat(catalog): readiness state replaces per-campaign activation"
```

---

### Task 3: Campaign assignment service

**Files:**
- Create: `app/Services/Pipelines/CampaignPipelineAssignment.php`
- Test: `tests/Feature/Pipelines/CampaignPipelineAssignmentTest.php`

**Interfaces:**
- Produces: `CampaignPipelineAssignment::assign(Campaign, Pipeline, int $sortOrder = 0): void`, `remove(Campaign, Pipeline): void`, `reorder(Campaign, Pipeline, int $sortOrder): void`. All throw `ValidationException` with key `pipeline`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Pipelines/CampaignPipelineAssignmentTest.php`:

```php
<?php

use App\Models\Campaign;
use App\Models\Pipeline;
use App\Services\Pipelines\CampaignPipelineAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('assigns a ready app with an order and takes a campaign row lock', function (): void {
    $sqls = [];
    DB::listen(function ($query) use (&$sqls): void {
        $sqls[] = $query->sql;
    });
    $campaign = Campaign::factory()->create();
    $pipeline = Pipeline::factory()->generator()->ready()->create();

    app(CampaignPipelineAssignment::class)->assign($campaign, $pipeline, 4);

    expect($campaign->pipelines()->first()?->pivot->sort_order)->toBe(4)
        ->and(collect($sqls)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'for update')))->toBeTrue();
});

it('rejects apps that are not ready or already assigned', function (): void {
    $campaign = Campaign::factory()->create();
    $notReady = Pipeline::factory()->generator()->create();
    $ready = Pipeline::factory()->generator()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);

    expect(fn () => $service->assign($campaign, $notReady))->toThrow(ValidationException::class);
    $service->assign($campaign, $ready);
    expect(fn () => $service->assign($campaign, $ready))->toThrow(ValidationException::class)
        ->and($campaign->pipelines()->count())->toBe(1);
});

it('allows several generators but one editor and one upscaler per campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $service = app(CampaignPipelineAssignment::class);

    $service->assign($campaign, Pipeline::factory()->generator()->ready()->create());
    $service->assign($campaign, Pipeline::factory()->generator()->ready()->create());
    $service->assign($campaign, Pipeline::factory()->editor()->ready()->create());
    $service->assign($campaign, Pipeline::factory()->upscaler()->ready()->create());

    try {
        $service->assign($campaign, Pipeline::factory()->editor()->ready()->create());
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['pipeline'])->toBe(['Ya hay un editor activo en esta campaña.']);
    }
    expect(fn () => $service->assign($campaign, Pipeline::factory()->upscaler()->ready()->create()))->toThrow(ValidationException::class)
        ->and($campaign->pipelines()->count())->toBe(4);
});

it('allows the same app in several campaigns', function (): void {
    $pipeline = Pipeline::factory()->editor()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);

    $service->assign(Campaign::factory()->create(), $pipeline);
    $service->assign(Campaign::factory()->create(), $pipeline);

    expect($pipeline->campaigns()->count())->toBe(2);
});

it('removes an assignment and clears the default generator', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = Pipeline::factory()->generator()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);
    $service->assign($campaign, $pipeline);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);

    $service->remove($campaign, $pipeline);

    expect($campaign->pipelines()->count())->toBe(0)
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull()
        ->and(Pipeline::query()->whereKey($pipeline->id)->exists())->toBeTrue();
});

it('reorders only assigned apps', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = Pipeline::factory()->generator()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);
    $service->assign($campaign, $pipeline, 1);

    $service->reorder($campaign, $pipeline, 7);
    expect($campaign->pipelines()->first()?->pivot->sort_order)->toBe(7);

    expect(fn () => $service->reorder($campaign, Pipeline::factory()->ready()->create(), 1))->toThrow(ValidationException::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `ddev pest tests/Feature/Pipelines/CampaignPipelineAssignmentTest.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Implement the service**

`app/Services/Pipelines/CampaignPipelineAssignment.php`:

```php
<?php

namespace App\Services\Pipelines;

use App\Enums\PipelineKind;
use App\Models\Campaign;
use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignPipelineAssignment
{
    public function assign(Campaign $campaign, Pipeline $pipeline, int $sortOrder = 0): void
    {
        DB::transaction(function () use ($campaign, $pipeline, $sortOrder): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentPipeline = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());

            if (! $currentPipeline->is_ready) {
                $this->invalid('La app no está lista; revisa su configuración en el catálogo.');
            }

            if ($currentCampaign->pipelines()->whereKey($currentPipeline->id)->exists()) {
                $this->invalid('La app ya está asignada a esta campaña.');
            }

            if (in_array($currentPipeline->kind, [PipelineKind::Editor, PipelineKind::Upscaler], true)
                && $currentCampaign->pipelines()->where('pipelines.kind', $currentPipeline->kind)->exists()) {
                $this->invalid("Ya hay un {$currentPipeline->kind->value} activo en esta campaña.");
            }

            $currentCampaign->pipelines()->attach($currentPipeline->id, ['sort_order' => max(0, $sortOrder)]);
        });
    }

    public function remove(Campaign $campaign, Pipeline $pipeline): void
    {
        DB::transaction(function () use ($campaign, $pipeline): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());
            $currentCampaign->pipelines()->detach($pipeline->getKey());

            if ($currentCampaign->default_pipeline_id === $pipeline->getKey()) {
                $currentCampaign->update(['default_pipeline_id' => null]);
            }
        });
    }

    public function reorder(Campaign $campaign, Pipeline $pipeline, int $sortOrder): void
    {
        DB::transaction(function () use ($campaign, $pipeline, $sortOrder): void {
            $currentCampaign = Campaign::query()->withTrashed()->lockForUpdate()->findOrFail($campaign->getKey());

            if (! $currentCampaign->pipelines()->whereKey($pipeline->getKey())->exists()) {
                $this->invalid('La app no está asignada a esta campaña.');
            }

            $currentCampaign->pipelines()->updateExistingPivot($pipeline->getKey(), ['sort_order' => max(0, $sortOrder)]);
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['pipeline' => [$message]]);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `ddev pest tests/Feature/Pipelines/CampaignPipelineAssignmentTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/app/Services/Pipelines/CampaignPipelineAssignment.php app/tests/Feature/Pipelines/CampaignPipelineAssignmentTest.php
git commit -m "feat(catalog): campaign pipeline assignment service"
```

---

### Task 4: Schema sync with the studio key

**Files:**
- Modify: `app/Engines/EngineResolver.php`
- Modify: `app/Services/Pipelines/PipelineSchemaSync.php`
- Test: `tests/Feature/Pipelines/PipelineSchemaSyncTest.php`, `tests/Unit/Engines/EngineResolverTest.php` (create if there is no engine resolver test; check `tests/` first)

**Interfaces:**
- Produces: `EngineResolver::forStudio(): ImageEngine`.
- Consumes: `PipelineActivation::markNotReady(Pipeline, array)` from Task 2.

- [ ] **Step 1: Write the failing resolver test**

Add to the existing engine resolver test file if one exists, else create `tests/Feature/Engines/EngineResolverTest.php`:

```php
<?php

use App\Engines\EngineResolver;
use App\Engines\FakeEngine;
use App\Engines\Krea\KreaEngine;
use App\Engines\KreaException;

it('resolves the studio engine from the configured studio key', function (): void {
    config()->set('media.krea.key', 'studio-key');

    expect(app(EngineResolver::class)->forStudio())->toBeInstanceOf(KreaEngine::class);
});

it('refuses a studio engine without a key', function (): void {
    config()->set('media.krea.key', null);

    expect(fn () => app(EngineResolver::class)->forStudio())->toThrow(KreaException::class);
});

it('returns the fake engine for the studio when it is bound', function (): void {
    fakeEngine();

    expect(app(EngineResolver::class)->forStudio())->toBeInstanceOf(FakeEngine::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `ddev pest tests/Feature/Engines/EngineResolverTest.php`
Expected: FAIL with undefined method `forStudio`.

- [ ] **Step 3: Add `forStudio` and share the key logic**

Replace the body of `app/Engines/EngineResolver.php`:

```php
final class EngineResolver
{
    public function forBrand(Brand $brand): ImageEngine
    {
        return $this->forSource($brand, $brand->resolveKreaKeySource());
    }

    public function forSource(Brand $brand, string $source): ImageEngine
    {
        if (! in_array($source, ['brand', 'studio'], true)) {
            throw new KreaException(KreaErrorMessages::forStatus(401, null), 401);
        }

        return $this->withKey($source === 'brand' ? $brand->krea_api_key : config('media.krea.key'));
    }

    public function forStudio(): ImageEngine
    {
        return $this->withKey(config('media.krea.key'));
    }

    private function withKey(mixed $key): ImageEngine
    {
        if (app()->bound(FakeEngine::class)) {
            return app(FakeEngine::class);
        }

        if (blank($key)) {
            throw new KreaException(KreaErrorMessages::forStatus(401, null), 401);
        }

        return new KreaEngine((string) $key, (string) config('media.krea.base_url'));
    }
}
```

Keep the existing `use` statements.

- [ ] **Step 4: Update `PipelineSchemaSync`**

Replace the start of `sync()` and the tail of the transaction in `app/Services/Pipelines/PipelineSchemaSync.php`:

```php
public function sync(Pipeline $pipeline): Pipeline
{
    $providerRef = $pipeline->provider_ref;
    $schema = $this->engines->forStudio()->describe($providerRef);

    $locked = DB::transaction(function () use ($pipeline, $providerRef, $schema): Pipeline {
        $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->getKey());

        if ($current->provider_ref !== $providerRef) {
            throw new KreaException('La configuración del flujo cambió; vuelve a sincronizar.');
        }

        $isInitialSync = $current->schema_fetched_at === null && $current->input_schema === null;
        // … unchanged field diff code …

        $errors = $this->readiness->evaluate($current->refresh());
        if ($errors !== []) {
            $this->activation->markNotReady($current, $errors);
        } else {
            $current->forceFill(['readiness_errors' => []])->save();
        }

        return $current;
    });

    return $locked->refresh();
}
```

Remove `$campaignId`, `$brandId`, `$wasActive`, the `Campaign` lock, and the `use App\Models\Campaign;` import. A ready entry stays ready after a clean sync; it is never promoted to ready by a sync.

- [ ] **Step 5: Update the sync tests**

In `tests/Feature/Pipelines/PipelineSchemaSyncTest.php`:
- Replace `Pipeline::factory()->for($campaign)…` / `->for(Campaign::factory())` with `Pipeline::factory()` (attach with `attachPipeline($campaign, $pipeline)` only where the test asserts something about a campaign, such as a default being cleared).
- Replace `'is_active' => true` with `'is_ready' => true` and `->is_active` with `->is_ready`.
- Any test that put a Krea key on the brand to make the sync work now sets `config()->set('media.krea.key', 'studio-key')` or relies on `fakeEngine()`.
- Add one test:

```php
it('syncs with the studio key and never touches the brand key', function (): void {
    $brand = \App\Models\Brand::factory()->create(['krea_api_key' => 'brand-key']);
    $campaign = Campaign::factory()->for($brand)->create();
    $pipeline = attachPipeline($campaign, Pipeline::factory()->ready()->create());
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['prompt' => ['type' => 'string']]]);

    $synced = app(PipelineSchemaSync::class)->sync($pipeline);

    expect($synced->fields()->count())->toBe(1)->and($synced->is_ready)->toBeTrue();
});

it('drops readiness in every campaign when a resync introduces errors', function (): void {
    $first = Campaign::factory()->create();
    $second = Campaign::factory()->create();
    $pipeline = readyGenerator($first);
    attachPipeline($second, $pipeline);
    $first->update(['default_pipeline_id' => $pipeline->id]);
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['new' => ['type' => 'object']]]);

    app(PipelineSchemaSync::class)->sync($pipeline);

    expect($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($first->fresh()->default_pipeline_id)->toBeNull()
        ->and($first->activeGenerators()->count())->toBe(0)
        ->and($second->activeGenerators()->count())->toBe(0);
});
```

- [ ] **Step 6: Run the tests**

Run: `ddev pest tests/Feature/Engines/EngineResolverTest.php tests/Feature/Pipelines/PipelineSchemaSyncTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/app/Engines/EngineResolver.php app/app/Services/Pipelines/PipelineSchemaSync.php app/tests/Feature/Engines app/tests/Feature/Pipelines/PipelineSchemaSyncTest.php
git commit -m "feat(catalog): sync catalog schemas with the studio key"
```

---

### Task 5: Catalog fixed images and field configuration

**Files:**
- Modify: `app/Services/Media/InputUploadService.php`
- Modify: `app/Services/Pipelines/PipelineFieldConfiguration.php`
- Modify: `app/Console/Commands/CleanUnreferencedInputs.php` only if it filters by `brand_id` (check with `grep -n brand_id`); the `pipeline_inputs` retention is already there.
- Test: `tests/Feature/Media/InputUploadServiceTest.php`, `tests/Feature/Admin/PipelineResourceTest.php` (field tests), `tests/Feature/Media/CleanInputsTest.php`

**Interfaces:**
- Produces: `InputUploadService::finalizeForCatalog(string $temporaryPath, User $user): InputUpload` storing under `catalog/<uuid>.<ext>` with `brand_id = null`.
- Consumes: `PipelineActivation::markNotReady` from Task 2.

- [ ] **Step 1: Write the failing upload test**

Add to `tests/Feature/Media/InputUploadServiceTest.php`:

```php
it('finalizes catalog uploads under catalog/ without a brand', function (): void {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('tmp/fixed.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $user = User::factory()->artDirector()->create();

    $upload = app(InputUploadService::class)->finalizeForCatalog('tmp/fixed.png', $user);

    expect($upload->brand_id)->toBeNull()
        ->and($upload->user_id)->toBe($user->id)
        ->and($upload->storage_path)->toStartWith('catalog/')
        ->and(Storage::disk('inputs')->exists($upload->storage_path))->toBeTrue()
        ->and(Storage::disk('inputs')->exists('tmp/fixed.png'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `ddev pest tests/Feature/Media/InputUploadServiceTest.php`
Expected: FAIL with undefined method `finalizeForCatalog`.

- [ ] **Step 3: Refactor `InputUploadService`**

Rename the current `finalize` body into a private `store(string $temporaryPath, ?Brand $brand, User $user): InputUpload`, with these two lines changed:

```php
$storagePath = ($brand?->id ?? 'catalog').'/'.Str::uuid().'.'.$image['ext'];
// …
'brand_id' => $brand?->id,
```

Then add the two public entry points:

```php
public function finalize(string $temporaryPath, Brand $brand, User $user): InputUpload
{
    return $this->store($temporaryPath, $brand, $user);
}

public function finalizeForCatalog(string $temporaryPath, User $user): InputUpload
{
    return $this->store($temporaryPath, null, $user);
}
```

- [ ] **Step 4: Update `PipelineFieldConfiguration`**

- `existingImagePath()`: delete the line `abort_unless($upload->brand_id === $field->pipeline->campaign->brand_id, 403);`.
- `save()`: delete `$campaignId` and `$brandId`; replace `$this->uploads->finalize($path, $pipeline->campaign->brand, $user)` with `$this->uploads->finalizeForCatalog($path, $user)`.
- Transaction: remove the `Campaign` lock and simplify the guard:

```php
return DB::transaction(function () use ($pipeline, $original, $data, $hasFixedValue, $value, $upload): PipelineField {
    $current = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->id);
    $field = PipelineField::query()->where('pipeline_id', $current->id)->lockForUpdate()->findOrFail($original->id);
    if ($field->getAttributes() !== $original->getAttributes()) {
        throw ValidationException::withMessages(['fixed_value' => 'La configuración cambió; vuelve a abrir el campo.']);
    }
    // … unchanged field save and upload linking …
    $errors = $this->readiness->evaluate($current);
    $current->update(['readiness_errors' => $errors, 'config_revision' => $current->config_revision + 1]);
    if ($errors !== []) {
        $this->activation->markNotReady($current, $errors);
    }

    return $field;
});
```

Remove the `use App\Models\Campaign;` import.

- [ ] **Step 5: Update the field tests in `PipelineResourceTest`**

In `tests/Feature/Admin/PipelineResourceTest.php`:
- `Pipeline::factory()->create([...])` calls stay as they are (they never needed a campaign).
- In `invalidates active defaults and increments fresh revision once…`: keep `readyGenerator($campaign)` (already attaches) and change `->is_active)->toBeFalse()` to `->is_ready)->toBeFalse()`.
- In `refreshes and activates from the pipeline header…`: rename the action call `callAction('activate')` to `callAction('markReady')` and `->is_active)->toBeTrue()` to `->is_ready)->toBeTrue()` (the action itself is built in Task 7; this test will pass after Task 7).
- Any test that asserts a fixed image path starts with the brand id now expects `catalog/`.

- [ ] **Step 6: Update `CleanInputsTest`**

Replace `Pipeline::factory()->for($campaign)` with `Pipeline::factory()`; where a fixed-image upload was created with the brand, use `InputUpload::factory()->catalog()`. The retention assertion (upload linked in `pipeline_inputs` survives cleanup) stays.

- [ ] **Step 7: Run the tests**

Run: `ddev pest tests/Feature/Media/InputUploadServiceTest.php tests/Feature/Media/CleanInputsTest.php tests/Feature/Admin/PipelineResourceTest.php --filter='finalizes fixed images|parses valid scalar|invalidates active defaults'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/app/Services/Media/InputUploadService.php app/app/Services/Pipelines/PipelineFieldConfiguration.php app/tests/Feature/Media app/tests/Feature/Admin/PipelineResourceTest.php
git commit -m "feat(catalog): catalog fixed images without brand scope"
```

---

### Task 6: Generation and editor panel read through the pivot

**Files:**
- Modify: `app/Services/Generation/CreateGeneration.php:89-100, 222-236`
- Modify: `app/Services/Generation/RestartGeneration.php:147-170`
- Modify: `app/Filament/App/Pages/Generator.php:279-288`
- Modify: `app/Livewire/PieceViewer.php:270-277`
- Modify: `app/Http/Controllers/MediaController.php:80-88`
- Test: `tests/Feature/Generation/CreateGenerationTest.php`, `tests/Feature/Generation/RestartGenerationTest.php`, `tests/Feature/Generation/InputComposerTest.php`, `tests/Feature/App/GeneratorPageTest.php`, `tests/Feature/App/PieceViewerTest.php`, `tests/Feature/App/MediaControllerTest.php`, `tests/Feature/App/JobsBellTest.php`, `tests/Feature/Models/GenerationModelsTest.php`, `tests/Unit/Pipelines/PipelineFormBuilderTest.php`, `tests/Feature/Admin/PrivateFileUploadTest.php`

**Interfaces:**
- Consumes: `Campaign::pipelines()`, `activeGenerators()`, `attachPipeline()` from Task 1.

- [ ] **Step 1: Mechanical test migration**

In every test file listed above:
- `Pipeline::factory()->for($campaign)->create([...])` → `attachPipeline($campaign, Pipeline::factory()->create([...]))`.
- `Pipeline::factory()->for(Campaign::factory())->create()` → `Pipeline::factory()->create()` when no campaign is asserted, else create the campaign first and attach.
- `'is_active' => true` → `'is_ready' => true`; `->is_active` → `->is_ready`; `'sort_order' => N` inside a pipeline factory call → third argument of `attachPipeline`.
- `$pipeline->campaign` → `$pipeline->campaigns()->first()`.

Run: `grep -rn "is_active\|->for(\$campaign)\|campaign_id' =>" app/tests | grep -i pipeline` from the repo root and fix every hit.

- [ ] **Step 2: Add the new behavioral tests**

Append to `tests/Feature/Generation/CreateGenerationTest.php`:

```php
it('uses the first assigned ready editor by order and ignores unassigned or not ready ones', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    fakeEngine();
    $generator = readyGenerator($campaign);
    $piece = \App\Models\Piece::factory()->for($campaign)->create();
    $notReady = attachPipeline($campaign, editorPipeline(false), 0);
    $ready = attachPipeline($campaign, editorPipeline(true), 1);
    editorPipeline(true); // unassigned

    $generation = app(CreateGeneration::class)->edit($editor, $piece, 'más luz', (string) \Illuminate\Support\Str::uuid());

    expect($generation->pipeline_id)->toBe($ready->id);
});
```

Add this helper near the top of that test file (a plain function, like `readyPipeline` in the old activation test):

```php
function editorPipeline(bool $ready): Pipeline
{
    $pipeline = Pipeline::factory()->editor()->create([
        'is_ready' => $ready,
        'readiness_errors' => $ready ? [] : ['Un editor necesita exactamente una imagen y un prompt vinculados.'],
        'input_schema' => ['properties' => ['image' => ['type' => 'string'], 'prompt' => ['type' => 'string']]],
    ]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'prompt', 'role' => FieldRole::Prompt]);

    return $pipeline;
}
```

Adjust the `edit()` call signature to whatever `CreateGeneration::edit` actually takes (read lines 60-66 of the service); the assertion is what matters.

Append to `tests/Feature/App/MediaControllerTest.php`:

```php
it('serves a catalog fixed image to editors of any brand whose campaign uses the app and denies others', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $upload = \App\Models\InputUpload::factory()->catalog()->create(['finalized_at' => now()]);
    $pipeline->inputUploads()->attach($upload->id);
    Storage::fake('inputs');
    Storage::disk('inputs')->put($upload->storage_path, 'x');

    $this->get(route('media.upload', $upload))->assertRedirect();

    $stranger = User::factory()->editor()->create();
    $otherBrand = \App\Models\Brand::factory()->create();
    $otherBrand->users()->attach($stranger);
    $this->actingAs($stranger);
    Filament::setTenant($otherBrand);
    $this->get(route('media.upload', $upload))->assertForbidden();

    $this->actingAs(User::factory()->artDirector()->create());
    $this->get(route('media.upload', $upload))->assertRedirect();
});
```

Match the existing test file's way of hitting the route (look at its first test for the exact `get()`/tenant setup and copy it).

- [ ] **Step 3: Run to verify failure**

Run: `ddev pest tests/Feature/Generation tests/Feature/App`
Expected: FAIL on `campaign_id` column / `is_active` lookups.

- [ ] **Step 4: Update `CreateGeneration`**

Replace the lookup in `fromSource()`:

```php
$pipeline = $campaign->pipelines()
    ->where('pipelines.kind', $pipelineKind)
    ->where('pipelines.is_ready', true)
    ->first();

if ($pipeline === null) {
    $this->invalid("Esta campaña no tiene {$this->pipelineLabel($pipelineKind)} configurado.");
}
```

Replace the condition in `ensurePipeline()`:

```php
if ($pipeline === null
    || $campaign === null
    || $pipeline->kind !== $expected
    || ! $pipeline->isReady()
    || ! $campaign->pipelines()->whereKey($pipeline->id)->exists()) {
    $this->invalid("Esta campaña no tiene {$this->pipelineLabel($expected)} configurado.");
}
```

- [ ] **Step 5: Update `RestartGeneration::authorizeUploads`**

Replace the catch block:

```php
} catch (AuthorizationException $exception) {
    if ($pipeline === null || ! $campaign->pipelines()->whereKey($pipeline->id)->exists()) {
        throw $exception;
    }
    $this->uploads->authorizeForPipeline($id, $pipeline);
}
```

(The `brand_id` comparison is removed: catalog uploads have no brand.)

- [ ] **Step 6: Update the `Generator` page**

Replace `pipeline()`:

```php
private function pipeline(): ?Pipeline
{
    $campaign = $this->campaign();
    if ($this->pipelineId === null) {
        return null;
    }
    $pipeline = $campaign->pipelines()->where('pipelines.kind', PipelineKind::Generator)->find($this->pipelineId) ?? abort(404);

    return $pipeline->is_ready ? $pipeline : null;
}
```

- [ ] **Step 7: Update `PieceViewer::originatingInputs`**

Replace the `__upload` branch:

```php
} elseif (is_array($value) && isset($value['__upload']) && is_int($value['__upload'])) {
    $upload = InputUpload::query()->find($value['__upload']);
    $ownUpload = $upload !== null && $upload->brand_id === $this->tenant()->id && $upload->user_id === $this->user()->id;
    $fixedUpload = $upload !== null && $generation->pipeline !== null
        && $generation->pipeline->campaigns()->whereKey($piece->campaign_id)->exists()
        && $generation->pipeline->inputUploads()->whereKey($upload->id)->exists();
    if ($ownUpload || $fixedUpload) {
        $inputs[] = ['label' => $label, 'url' => route('media.upload', $upload)];
    }
}
```

- [ ] **Step 8: Update `MediaController::isFixedUpload`**

```php
private function isFixedUpload(InputUpload $upload, ?User $user = null): bool
{
    return Pipeline::query()
        ->whereHas('inputUploads', fn ($query) => $query->whereKey($upload->id))
        ->when($user, fn ($query) => $query->whereHas('campaigns.brand.users', fn ($query) => $query->whereKey($user->id)))
        ->exists();
}
```

- [ ] **Step 9: Run the tests**

Run: `ddev pest tests/Feature/Generation tests/Feature/App tests/Feature/Models tests/Unit tests/Feature/Admin/PrivateFileUploadTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/app/Services/Generation app/app/Filament/App/Pages/Generator.php app/app/Livewire/PieceViewer.php app/app/Http/Controllers/MediaController.php app/tests
git commit -m "feat(catalog): generation and editor panel read assigned catalog apps"
```

---

### Task 7: Admin catalog resource

**Files:**
- Modify: `app/Filament/Admin/Resources/Pipelines/PipelineResource.php`
- Create: `app/Filament/Admin/Resources/Pipelines/Pages/ListPipelines.php`
- Create: `app/Filament/Admin/Resources/Pipelines/Tables/PipelinesTable.php`
- Modify: `app/Filament/Admin/Resources/Pipelines/Pages/EditPipeline.php`
- Modify: `app/Filament/Admin/Resources/Pipelines/Actions/PipelineActions.php`
- Modify: `app/Filament/Admin/Resources/Pipelines/Schemas/PipelineForm.php` (add "Lista" entry)
- Modify: `app/Policies/PipelinePolicy.php` (`delete`)
- Test: `tests/Feature/Admin/PipelineResourceTest.php`

**Interfaces:**
- Produces: `PipelineActions::refresh()`, `PipelineActions::markReady()`, `PipelineActions::markNotReady()`; `PipelineActions::createSchema(): array` (the create form components) reused by the list page.
- Consumes: `PipelineActivation::markReady/markNotReady`, `PipelineSchemaSync::sync`.

- [ ] **Step 1: Write the failing resource tests**

Append to `tests/Feature/Admin/PipelineResourceTest.php`:

```php
use App\Filament\Admin\Resources\Pipelines\Pages\ListPipelines;

it('lists the catalog with readiness and campaign counts', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $used = readyGenerator($campaign);
    $idle = Pipeline::factory()->editor()->create(['label' => 'Editor libre']);

    Livewire::test(ListPipelines::class)
        ->assertCanSeeTableRecords([$used, $idle])
        ->assertTableColumnStateSet('campaigns_count', 1, $used)
        ->assertTableColumnStateSet('campaigns_count', 0, $idle)
        ->assertSee('Catálogo de apps');
});

it('creates a catalog entry, syncs its schema, and rejects a duplicate provider reference', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    fakeEngine()->withSchema('app-1', ['properties' => ['prompt' => ['type' => 'string']]]);

    Livewire::test(ListPipelines::class)
        ->callAction('create', data: ['kind' => 'generator', 'label' => 'Creador', 'provider_ref' => 'app-1'])
        ->assertHasNoActionErrors();
    $pipeline = Pipeline::query()->sole();
    expect($pipeline->fields()->count())->toBe(1)->and($pipeline->is_ready)->toBeFalse();

    Livewire::test(ListPipelines::class)
        ->callAction('create', data: ['kind' => 'editor', 'label' => 'Otro', 'provider_ref' => 'app-1'])
        ->assertHasActionErrors(['provider_ref']);
    expect(Pipeline::query()->count())->toBe(1);
});

it('leaves no catalog entry when the schema fetch fails', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    fakeEngine()->failingDescribe('app-x', 'No se encontró la app.');

    Livewire::test(ListPipelines::class)
        ->callAction('create', data: ['kind' => 'generator', 'label' => 'Roto', 'provider_ref' => 'app-x'])
        ->assertHasActionErrors(['provider_ref']);
    expect(Pipeline::query()->count())->toBe(0);
});

it('deletes only unassigned catalog entries', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $used = readyGenerator($campaign);
    $idle = Pipeline::factory()->create();

    Livewire::test(ListPipelines::class)
        ->assertTableActionHidden('delete', $used)
        ->callTableAction('delete', $idle);
    expect(Pipeline::query()->whereKey($idle->id)->exists())->toBeFalse()
        ->and(Pipeline::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('marks a catalog entry not ready from the header', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = readyGenerator(Campaign::factory()->create());

    Livewire::test(EditPipeline::class, ['record' => $pipeline->id])->callAction('markNotReady')->assertNotified();
    expect($pipeline->fresh()->is_ready)->toBeFalse();
});
```

If `FakeEngine` has no `failingDescribe` helper, look at how the existing test `surfaces safe provider validation and leaves no pipeline after failed schema creation` in `CampaignResourceTest` provokes a failed describe and copy that exact setup.

- [ ] **Step 2: Run to verify failure**

Run: `ddev pest tests/Feature/Admin/PipelineResourceTest.php`
Expected: FAIL with class `ListPipelines` not found.

- [ ] **Step 3: Rewrite `PipelineActions`**

```php
<?php

namespace App\Filament\Admin\Resources\Pipelines\Actions;

use App\Engines\KreaException;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineActivation;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class PipelineActions
{
    public const KINDS = ['generator' => 'Generador', 'editor' => 'Editor', 'upscaler' => 'Upscaler'];

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function createSchema(): array
    {
        return [
            Select::make('kind')->label('Tipo')->options(self::KINDS)->required(),
            TextInput::make('label')->label('Nombre')->required()->maxLength(255),
            TextInput::make('provider_ref')->label('Referencia del proveedor')->required()->maxLength(255)
                ->regex('/\A[A-Za-z0-9_.:\/-]+\z/')
                ->unique(table: Pipeline::class, column: 'provider_ref')
                ->validationMessages(['unique' => 'Esta referencia ya está en el catálogo.']),
        ];
    }

    public static function refresh(): Action
    {
        return Action::make('refresh')->label('Refrescar esquema')->authorize('update')->databaseTransaction(false)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                try {
                    app(PipelineSchemaSync::class)->sync($record->fresh());
                    Notification::make()->success()->title('Esquema actualizado.')->send();
                    $livewire->dispatch('pipeline-updated');
                } catch (KreaException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    public static function markReady(): Action
    {
        return Action::make('markReady')->label('Marcar lista')->authorize('update')
            ->visible(fn (Pipeline $record): bool => ! $record->is_ready)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                try {
                    app(PipelineActivation::class)->markReady($record);
                    Notification::make()->success()->title('App marcada como lista.')->send();
                    $livewire->dispatch('pipeline-updated');
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('No se pudo marcar la app como lista.')
                        ->body(implode("\n", Arr::flatten($exception->errors())))->send();
                }
            });
    }

    public static function markNotReady(): Action
    {
        return Action::make('markNotReady')->label('Marcar no lista')->authorize('update')->requiresConfirmation()
            ->modalDescription('La app desaparecerá del panel de editores en todas las campañas que la usan.')
            ->visible(fn (Pipeline $record): bool => $record->is_ready)
            ->action(function (Pipeline $record, Component $livewire): void {
                Gate::authorize('update', $record);
                app(PipelineActivation::class)->markNotReady($record);
                Notification::make()->success()->title('App marcada como no lista.')->send();
                $livewire->dispatch('pipeline-updated');
            });
    }
}
```

- [ ] **Step 4: Create the table and list page**

`app/Filament/Admin/Resources/Pipelines/Tables/PipelinesTable.php`:

```php
<?php

namespace App\Filament\Admin\Resources\Pipelines\Tables;

use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Models\Pipeline;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PipelinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')->label('Nombre')->searchable(),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state): string => PipelineActions::KINDS[$state->value]),
                TextColumn::make('provider_ref')->label('Referencia del proveedor')->copyable(),
                IconColumn::make('is_ready')->label('Lista')->boolean()
                    ->tooltip(fn (Pipeline $record): string => implode("\n", $record->readiness_errors ?? [])),
                TextColumn::make('campaigns_count')->label('Campañas')->counts('campaigns'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(PipelineActions::KINDS),
            ])
            ->recordActions([
                EditAction::make()->label('Configurar'),
                PipelineActions::refresh(),
                PipelineActions::markReady(),
                PipelineActions::markNotReady(),
                DeleteAction::make()->label('Eliminar'),
            ]);
    }
}
```

`app/Filament/Admin/Resources/Pipelines/Pages/ListPipelines.php`:

```php
<?php

namespace App\Filament\Admin\Resources\Pipelines\Pages;

use App\Engines\KreaException;
use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineSchemaSync;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ListPipelines extends ListRecords
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva app')->modalHeading('Nueva app del catálogo')
                ->schema(PipelineActions::createSchema())
                ->databaseTransaction(false)
                ->before(fn () => Gate::authorize('create', Pipeline::class))
                ->after(function (Pipeline $record): void {
                    try {
                        app(PipelineSchemaSync::class)->sync($record);
                    } catch (KreaException $exception) {
                        $record->delete();
                        throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.provider_ref' => $exception->getMessage()]);
                    }
                }),
        ];
    }
}
```

- [ ] **Step 5: Update the resource, edit page, form, and policy**

`PipelineResource`: remove `$shouldRegisterNavigation = false` and `getIndexUrl()`; set

```php
protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;
protected static ?string $modelLabel = 'app del catálogo';
protected static ?string $pluralModelLabel = 'Catálogo de apps';
protected static ?string $navigationLabel = 'Catálogo de apps';
protected static ?int $navigationSort = 3;

public static function table(Table $table): Table
{
    return PipelinesTable::configure($table);
}

public static function getPages(): array
{
    return [
        'index' => ListPipelines::route('/'),
        'edit' => EditPipeline::route('/{record}/edit'),
    ];
}
```

Add the imports (`BackedEnum`, `Heroicon`, `Table`, `ListPipelines`, `PipelinesTable`). Check `navigationSort` of Brands/Users/Campaigns resources and pick a value that places the catalog after Campañas.

`EditPipeline::getHeaderActions()`:

```php
return [PipelineActions::refresh(), PipelineActions::markReady(), PipelineActions::markNotReady(), DeleteAction::make()->label('Eliminar')];
```

`PipelineForm`: add after the kind entry

```php
IconEntry::make('is_ready')->label('Lista')->boolean(),
TextEntry::make('campaigns_count')->label('Campañas que la usan')->state(fn (Pipeline $record): int => $record->campaigns()->count()),
```

`PipelinePolicy::delete`:

```php
public function delete(User $user, Pipeline $record): bool
{
    return $user->fresh()?->isArtDirector() === true && $record->campaigns()->doesntExist();
}
```

- [ ] **Step 6: Run the tests**

Run: `ddev pest tests/Feature/Admin/PipelineResourceTest.php`
Expected: PASS, including the older `refreshes and activates from the pipeline header…` test renamed in Task 5.

- [ ] **Step 7: Commit**

```bash
git add app/app/Filament/Admin/Resources/Pipelines app/app/Policies/PipelinePolicy.php app/tests/Feature/Admin/PipelineResourceTest.php
git commit -m "feat(admin): catalog resource with create, readiness, and delete"
```

---

### Task 8: Campaign assignment UI

**Files:**
- Modify: `app/Filament/Admin/Resources/Campaigns/RelationManagers/PipelinesRelationManager.php` (full rewrite)
- Modify: `app/Filament/Admin/Resources/Campaigns/Schemas/CampaignForm.php:36-37` (no code change needed if `activeGenerators()` returns the pivot relation; verify the select still lists labels)
- Modify: `app/Filament/Admin/Resources/Campaigns/Pages/EditCampaign.php` (no change; `setDefault` keeps its name)
- Modify: `app/Filament/Admin/Resources/Campaigns/Tables/CampaignsTable.php:23` label `'Flujos'` → `'Apps'`
- Test: `tests/Feature/Admin/CampaignResourceTest.php`

**Interfaces:**
- Consumes: `CampaignPipelineAssignment` from Task 3, `PipelineResource::getUrl('edit')`.

- [ ] **Step 1: Rewrite the campaign resource tests that touched pipelines**

In `tests/Feature/Admin/CampaignResourceTest.php` delete the tests `creates a pipeline through its campaign…`, `surfaces safe provider validation…`, the "activate" test at lines 44-57, `refreshes a pipeline from its campaign and deactivates an invalid default`, and the two tests around lines 101-133 that create/refresh pipelines from the relation manager. Keep the default-generator tests (they use `readyGenerator`, which now attaches). Add:

```php
use App\Services\Pipelines\CampaignPipelineAssignment;

it('assigns a ready catalog app with an order and lists only unassigned ready apps', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $ready = Pipeline::factory()->generator()->ready()->create(['label' => 'Creador']);
    $notReady = Pipeline::factory()->generator()->create(['label' => 'Pendiente']);
    $assigned = readyGenerator($campaign);

    $component = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class]);
    $component->mountAction('assign')
        ->assertSchemaComponentExists('pipeline_id')
        ->assertActionDataSet(['sort_order' => 0]);
    $options = $component->instance()->getMountedActionSchema()->getComponent('pipeline_id')->getOptions();
    expect(array_keys($options))->toBe([$ready->id]);

    $component->callAction('assign', data: ['pipeline_id' => $ready->id, 'sort_order' => 2])->assertHasNoActionErrors()->assertNotified();
    expect($campaign->pipelines()->pluck('pipelines.id')->all())->toBe([$assigned->id, $ready->id]);
});

it('surfaces assignment rule violations on the select', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    attachPipeline($campaign, Pipeline::factory()->editor()->ready()->create());
    $second = Pipeline::factory()->editor()->ready()->create();

    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->callAction('assign', data: ['pipeline_id' => $second->id, 'sort_order' => 0])
        ->assertHasActionErrors(['pipeline_id']);
    expect($campaign->pipelines()->count())->toBe(1);
});

it('reorders and removes assignments without deleting the catalog entry', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $component = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class]);

    $component->callAction(TestAction::make('reorder')->table($pipeline), data: ['sort_order' => 5])->assertHasNoActionErrors();
    expect($campaign->pipelines()->first()?->pivot->sort_order)->toBe(5);

    $component->callAction(TestAction::make('remove')->table($pipeline))->assertNotified();
    expect($campaign->pipelines()->count())->toBe(0)
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull()
        ->and(Pipeline::query()->whereKey($pipeline->id)->exists())->toBeTrue();
});

it('does not offer create, edit fields, or refresh from the campaign', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);

    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('refresh')
        ->assertTableActionExists('openCatalog')
        ->assertSee('Apps');
});
```

If `assertSchemaComponentExists`/`getOptions` differ in the installed Filament, replace the options assertion with a call to a small public method on the relation manager, `assignableOptions(): array`, defined in Step 3, and assert on its return value directly.

- [ ] **Step 2: Run to verify failure**

Run: `ddev pest tests/Feature/Admin/CampaignResourceTest.php`
Expected: FAIL (action `assign` missing).

- [ ] **Step 3: Rewrite the relation manager**

```php
<?php

namespace App\Filament\Admin\Resources\Campaigns\RelationManagers;

use App\Filament\Admin\Resources\Pipelines\Actions\PipelineActions;
use App\Filament\Admin\Resources\Pipelines\PipelineResource;
use App\Models\Pipeline;
use App\Services\Pipelines\CampaignPipelineAssignment;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PipelinesRelationManager extends RelationManager
{
    protected static string $relationship = 'pipelines';

    protected static ?string $title = 'Apps';

    public function boot(): void
    {
        abort_unless(auth()->user()?->fresh()?->isArtDirector(), 403);
    }

    /** @return array<int, string> */
    public function assignableOptions(): array
    {
        return Pipeline::query()
            ->where('is_ready', true)
            ->whereDoesntHave('campaigns', fn ($query) => $query->whereKey($this->getOwnerRecord()->getKey()))
            ->orderBy('kind')->orderBy('label')
            ->get()
            ->mapWithKeys(fn (Pipeline $pipeline): array => [$pipeline->id => PipelineActions::KINDS[$pipeline->kind->value].' · '.$pipeline->label])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('label')->columns([
            TextColumn::make('pivot.sort_order')->label('Orden'),
            TextColumn::make('label')->label('Nombre'),
            TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state): string => PipelineActions::KINDS[$state->value]),
            TextColumn::make('provider_ref')->label('Referencia del proveedor'),
            IconColumn::make('is_ready')->label('Lista')->boolean()
                ->tooltip(fn (Pipeline $record): string => implode("\n", $record->readiness_errors ?? [])),
        ])->headerActions([
            Action::make('assign')->label('Asignar app')->modalHeading('Asignar app del catálogo')
                ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                ->schema([
                    Select::make('pipeline_id')->label('App')->required()->searchable()
                        ->options(fn (): array => $this->assignableOptions())
                        ->helperText('Solo aparecen apps listas que aún no están asignadas.'),
                    TextInput::make('sort_order')->label('Orden')->integer()->minValue(0)->default(0)->required(),
                ])
                ->action(function (array $data): void {
                    $pipeline = Pipeline::query()->find($data['pipeline_id']);
                    if ($pipeline === null) {
                        $this->failAssign(['La app no está disponible.']);
                    }
                    try {
                        app(CampaignPipelineAssignment::class)->assign($this->getOwnerRecord(), $pipeline, (int) $data['sort_order']);
                    } catch (ValidationException $exception) {
                        $this->failAssign(Arr::flatten($exception->errors()));
                    }
                    Notification::make()->success()->title('App asignada.')->send();
                }),
        ])->recordActions([
            Action::make('reorder')->label('Cambiar orden')
                ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                ->fillForm(fn (Pipeline $record): array => ['sort_order' => (int) $record->pivot->sort_order])
                ->schema([TextInput::make('sort_order')->label('Orden')->integer()->minValue(0)->required()])
                ->action(function (Pipeline $record, array $data): void {
                    app(CampaignPipelineAssignment::class)->reorder($this->getOwnerRecord(), $record, (int) $data['sort_order']);
                }),
            Action::make('openCatalog')->label('Abrir en catálogo')
                ->url(fn (Pipeline $record): string => PipelineResource::getUrl('edit', ['record' => $record])),
            Action::make('remove')->label('Quitar')->color('danger')->requiresConfirmation()
                ->modalDescription('La app dejará de estar disponible en esta campaña. Los resultados ya generados se conservan.')
                ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                ->action(function (Pipeline $record): void {
                    app(CampaignPipelineAssignment::class)->remove($this->getOwnerRecord(), $record);
                    Notification::make()->success()->title('App quitada de la campaña.')->send();
                }),
        ]);
    }

    /** @param list<string> $messages */
    private function failAssign(array $messages): never
    {
        throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.pipeline_id' => $messages]);
    }
}
```

If `TextColumn::make('pivot.sort_order')` renders empty, use `->state(fn (Pipeline $record): int => (int) $record->pivot->sort_order)` on a column named `sort_order`.

- [ ] **Step 4: Rename the campaigns table column label**

`CampaignsTable.php` line 23: `->label('Apps')`.

- [ ] **Step 5: Run the tests**

Run: `ddev pest tests/Feature/Admin/CampaignResourceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/app/Filament/Admin/Resources/Campaigns app/tests/Feature/Admin/CampaignResourceTest.php
git commit -m "feat(admin): assign catalog apps to campaigns"
```

---

### Task 9: Catalog seeder from `.env`

**Files:**
- Modify: `config/media.php`
- Modify: `.env.example`
- Create: `database/seeders/PipelineCatalogSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Seeders/PipelineCatalogSeederTest.php`

**Interfaces:**
- Produces: `config('media.krea.test_apps')` array keyed `generator|editor|upscaler|skechers|invierno`; `PipelineCatalogSeeder`.

- [ ] **Step 1: Write the failing seeder test**

`tests/Feature/Seeders/PipelineCatalogSeederTest.php`:

```php
<?php

use App\Engines\FakeEngine;
use App\Models\Pipeline;
use Database\Seeders\PipelineCatalogSeeder;

it('creates one catalog entry per configured id and skips empty and duplicate ids', function (): void {
    config()->set('media.krea.test_apps', [
        'generator' => 'gen-1',
        'editor' => 'edit-1',
        'upscaler' => 'edit-1',
        'skechers' => 'sk-1',
        'invierno' => '',
    ]);

    $this->seed(PipelineCatalogSeeder::class);

    expect(Pipeline::query()->orderBy('id')->get()->map(fn (Pipeline $pipeline): array => [$pipeline->kind->value, $pipeline->label, $pipeline->provider_ref, $pipeline->is_ready])->all())
        ->toBe([
            ['generator', 'Creador Santander v3', 'gen-1', false],
            ['editor', 'Editor Santander v2', 'edit-1', false],
            ['generator', 'Fotos Skechers', 'sk-1', false],
        ]);
});

it('is idempotent and never touches schema, readiness, or the engine', function (): void {
    config()->set('media.krea.test_apps', ['generator' => 'gen-1', 'editor' => null, 'upscaler' => null, 'skechers' => null, 'invierno' => null]);
    $this->seed(PipelineCatalogSeeder::class);
    $pipeline = Pipeline::query()->sole();
    $pipeline->update(['label' => 'Renombrada', 'input_schema' => ['properties' => []], 'is_ready' => true, 'readiness_errors' => []]);
    $engine = fakeEngine();

    $this->seed(PipelineCatalogSeeder::class);

    expect(Pipeline::query()->count())->toBe(1)
        ->and($pipeline->fresh()->label)->toBe('Renombrada')
        ->and($pipeline->fresh()->is_ready)->toBeTrue()
        ->and($pipeline->fresh()->input_schema)->toBe(['properties' => []])
        ->and($engine->describeCalls ?? [])->toBe([]);
});
```

If `FakeEngine` does not expose `describeCalls`, assert instead with `expect(fn () => $engine->describe('gen-1'))` never being needed: replace the last expectation with a `Mockery::mock(EngineResolver::class)->shouldNotReceive('forStudio')` bound with `$this->app->instance(EngineResolver::class, …)` before the second seed.

- [ ] **Step 2: Run to verify failure**

Run: `ddev pest tests/Feature/Seeders/PipelineCatalogSeederTest.php`
Expected: FAIL with class not found.

- [ ] **Step 3: Add config and env keys**

`config/media.php` inside `'krea' => [ … ]`:

```php
'test_apps' => [
    'generator' => env('KREA_TEST_APP_ID_GENERATOR'),
    'editor' => env('KREA_TEST_APP_ID_EDITOR'),
    'upscaler' => env('KREA_TEST_APP_ID_UPSCALER'),
    'skechers' => env('KREA_TEST_APP_ID_SKECHERS'),
    'invierno' => env('KREA_TEST_APP_ID_INVIERNO'),
],
```

`.env.example` after `KREA_BASE_URL`:

```
# Catalog seed (local only). One Krea node-app version id per line; empty values are skipped.
KREA_TEST_APP_ID_GENERATOR=
KREA_TEST_APP_ID_EDITOR=
KREA_TEST_APP_ID_UPSCALER=
KREA_TEST_APP_ID_SKECHERS=
KREA_TEST_APP_ID_INVIERNO=
```

- [ ] **Step 4: Write the seeder**

`database/seeders/PipelineCatalogSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\PipelineKind;
use App\Models\Pipeline;
use Illuminate\Database\Seeder;

class PipelineCatalogSeeder extends Seeder
{
    /** @var array<string, array{kind: PipelineKind, label: string}> */
    private const APPS = [
        'generator' => ['kind' => PipelineKind::Generator, 'label' => 'Creador Santander v3'],
        'editor' => ['kind' => PipelineKind::Editor, 'label' => 'Editor Santander v2'],
        'upscaler' => ['kind' => PipelineKind::Upscaler, 'label' => 'Upscaler Santander'],
        'skechers' => ['kind' => PipelineKind::Generator, 'label' => 'Fotos Skechers'],
        'invierno' => ['kind' => PipelineKind::Generator, 'label' => 'Generador Invierno'],
    ];

    public function run(): void
    {
        $configured = (array) config('media.krea.test_apps', []);
        $seen = [];

        foreach (self::APPS as $key => $app) {
            $providerRef = trim((string) ($configured[$key] ?? ''));

            if ($providerRef === '') {
                $this->command?->line("Catálogo: {$key} sin id, se omite.");

                continue;
            }

            if (in_array($providerRef, $seen, true)) {
                $this->command?->warn("Catálogo: {$key} repite el id de otra app, se omite.");

                continue;
            }

            $seen[] = $providerRef;

            Pipeline::query()->firstOrCreate(
                ['engine' => 'krea', 'provider_ref' => $providerRef],
                ['kind' => $app['kind'], 'label' => $app['label']],
            );
        }
    }
}
```

`DatabaseSeeder::run()` adds `PipelineCatalogSeeder::class` to the `call([...])` list after `ArtDirectorSeeder::class`.

- [ ] **Step 5: Run the tests**

Run: `ddev pest tests/Feature/Seeders/PipelineCatalogSeederTest.php`
Expected: PASS.

- [ ] **Step 6: Fill the local `.env` and seed**

The user's `.env` (never committed) gets the four ids from the prototype: generator `fbe97b3b-d810-4de4-859f-49aa2a7887ab`, editor `1276c054-ee4c-4ec4-8e2f-8c8b9901557b`, skechers `93a86f3d-f237-4eea-92c5-cb21d5eca47b`, invierno `c77d7e41-fee5-4a53-8751-66309ddf33ce`; upscaler stays empty (same id as the editor). Then:

Run: `ddev artisan db:seed --class=PipelineCatalogSeeder --no-interaction`
Expected: four rows in `pipelines`, one "sin id" line for the upscaler.

- [ ] **Step 7: Commit**

```bash
git add app/config/media.php app/.env.example app/database/seeders app/tests/Feature/Seeders
git commit -m "feat(catalog): seed catalog entries from KREA_TEST_APP_ID_* env vars"
```

---

### Task 10: Full verification and docs

**Files:**
- Modify: `README.md` (repo root) — the walkthrough section that creates pipelines per campaign
- Modify: `docs/superpowers/specs/2026-09-09-pipeline-catalog-design.md` — status line

- [ ] **Step 1: Search for leftovers**

Run from the repo root:

```bash
grep -rn "is_active\|campaign_id" app/app --include='*.php' | grep -i pipeline
grep -rn "activate(\|deactivate(" app/app --include='*.php'
grep -rn "Flujos\|flujo" app/app/Filament --include='*.php'
```

Expected: no hits for the first two. For the third, rename user-facing "flujo" strings in the Pipelines and Campaigns resources to "app" wording where they refer to catalog entries (`'Flujo activado.'` no longer exists; `EditPipeline::$subheading` stays).

- [ ] **Step 2: Run the whole suite and Pint**

Run: `ddev pest --compact` then `ddev pint --dirty --format agent`
Expected: all tests pass (one deferred live Krea test skipped), Pint clean.

- [ ] **Step 3: Browser check**

`ddev artisan migrate:fresh --seed --no-interaction`, then in `/admin`: open Catálogo de apps, refresh schema of Creador Santander v3 (fake engine), configure fields, Marcar lista; open a campaign, Asignar app, set Generador por defecto. In `/app`, sign in as `test@example.com`, open the campaign, generate once. Record what you saw in `docs/superpowers/research/2026-09-09-catalog-acceptance.md` (short list, no claims beyond what you observed).

- [ ] **Step 4: Update docs**

README walkthrough: replace the "create a pipeline inside the campaign" steps with "create the app in Catálogo de apps (or seed it with the `KREA_TEST_APP_ID_*` variables), mark it ready, then assign it to the campaign". Spec status line: `**Status:** implemented on <date>.`

- [ ] **Step 5: Commit**

```bash
git add README.md docs
git commit -m "docs: catalog walkthrough and acceptance notes"
```

---

## Self-review notes

- Spec §3 data model → Task 1. §4 services → Tasks 2-6. §5 admin → Tasks 7-8. §6 editor panel → Task 6. §7 seeder → Task 9. §8 tests → each task plus Task 10. §9 out of scope → nothing added.
- Spec correction applied here: `PipelineReadiness` is **not** unchanged; its fixed-image check dropped the brand comparison (Task 2 Step 4). Spec §4 line updated accordingly.
- Names used consistently: `markReady`, `markNotReady`, `setDefault`, `assign`, `remove`, `reorder`, `forStudio`, `finalizeForCatalog`, `attachPipeline`, `readyGenerator`, `assignableOptions`, actions `assign`, `reorder`, `remove`, `openCatalog`, `markReady`, `markNotReady`, `refresh`, `create`, `delete`.
