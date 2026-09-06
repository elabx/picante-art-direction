# Muse Media Ops — Slice 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the slice 1 Media Ops product: a Laravel 12 + Filament 5 workspace where Art Directors register Krea node-app pipelines per campaign and brand Editors generate, edit, and 4K-upscale images that are copied into private S3 storage and served through signed URLs.

**Architecture:** Two Filament panels (`/admin` for Art Directors, `/app` for Editors with Brand tenancy) over a small domain layer: `PipelineSchemaSync` turns a Krea node-app schema into configurable fields, `PipelineFormBuilder` renders them, and a queued `GenerationRunner` chain (create → submit → poll → download) talks to Krea through an `ImageEngine` interface with a single `KreaEngine` implementation. All images live in object storage (MinIO in dev, S3 + CloudFront in production).

**Tech Stack:** PHP 8.2, Laravel 12, Filament 5, Livewire 4, Tailwind 4.1+, MySQL, Laravel queues (database driver in dev), Pest 3, AWS SDK for PHP (S3 + CloudFront `UrlSigner`), MinIO via Docker Compose, Laravel Boost + Filament Blueprint (dev).

**Spec:** `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-design.md` — read it first; the plan argues from it. Review with disposition table: `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-review.md`. Codex plan review and how each finding was applied: `docs/superpowers/plans/2026-09-05-codex-plan-review.md` and the **Revision log** at the end of this plan.

## Global Constraints

- Filament 5 requires **Livewire 4** and **Tailwind CSS 4.1+**; PHP **8.2+**; Laravel **12**. Lock a compatible set at scaffold time and commit `composer.lock`.
- **All UI text is Spanish.** Copy strings are given verbatim in tasks; do not translate or paraphrase them.
- **Standard Filament layouts only.** No custom theme in this slice (Modernist theme is slice 2).
- **Object storage everywhere.** No image bytes are written to the web server's local disk, including Livewire temporary uploads. Dev uses MinIO; tests use `Storage::fake()`.
- **Krea credentials never reach the browser, logs, snapshots, or queue payloads.** Resolve the key at dispatch from the pinned source (`brand` or `studio`).
- **Never call `submit` twice for one Generation.** Only the `pending → submitting` claim authorizes a provider execution. Recovery re-inspects known job IDs; a new paid execution requires the Editor's confirmation modal.
- **Provider-neutral names** in the schema: `engine`, `provider_ref`, `provider_job_id`, `source_url`.
- Signed URLs expire after **10 minutes**; issue them only after authorizing the owning record.
- Upload limit **20 MiB per image** (`max:20480`); aggregate request limit `media.max_request_bytes` (default 96 MiB).
- **Queue budgets:** `retry_after = 420` s on the database and redis queue connections; job timeouts `RunGenerationJob 90`, `PollGenerationJob 60`, `DownloadOutputJob 120`; the worker runs with `--timeout=150 --tries=1`. Worker timeout must stay below `retry_after`.
- **Every test boots Laravel.** `tests/Pest.php` applies `Tests\TestCase` and `RefreshDatabase` to both `Feature` and `Unit` directories.
- **Logs never contain** keys, data URLs, full input bodies, or signed URLs. Provider error details go through `KreaErrorMessages::sanitizeDetail()` (redact, then truncate to 2 KiB) before storage or logging.
- 4K rule: `s = 3840 / max(W, H)`, accepted size `round(W·s) × round(H·s)`; `is_4k` only for kind `upscale` pieces that pass.
- Commit after every task with a conventional-commit message ending in the attribution trailer used in this repo.
- **Working directory for all commands below is `app/`** (the Laravel app inside this repository) unless stated otherwise.
- Filament API signatures in this plan follow the Filament 5 docs (`Filament\Schemas\Schema`, `form(Schema $schema): Schema`, `table(Table $table): Table`). If the installed version differs, run Boost's `search-docs` for the installed docs and adapt the signature, keeping behavior identical.

## Prerequisites from the user (blocking items, ask once, up front)

1. Filament license credentials for `packages.filamentphp.com` (email + license key), entered by the user via `composer config --auth …` — never pasted into chat or committed.
2. A **fresh** Krea API key (the prototype key must be rotated), provided through `.env` only. Needed from **Gate A (Task 6b)** onward, not at the end.
3. The actual Krea node-app version IDs for the generator(s), editor, and upscaler.
4. Approval of the **Gate B** smoke run (Task 7b, ceiling US$10) before engine work is marked done.

---

## File structure

```
app/                                    Laravel application (created in Task 1)
├── app/Enums/                          UserRole, PipelineKind, InputType, FieldRole, FieldVisibility,
│                                       GenerationKind, GenerationStatus, FailureReason, PieceKind, OutputStatus
├── app/Models/                         Brand, User, Campaign, Pipeline, PipelineField, InputUpload,
│                                       Generation, GenerationJob, GenerationOutput, Piece
├── app/Engines/                        ImageEngine (interface), KreaException, EngineResolver, FakeEngine
│   ├── Data/                           EngineSchema, SubmissionOutcome, JobObservation, OutputRef
│   └── Krea/KreaEngine.php
├── app/Services/Pipelines/             SchemaSubset, PipelineSchemaSync, PipelineReadiness, PipelineFormBuilder
├── app/Services/Media/                 SignedUrlProvider (interface), CloudFrontSignedUrlProvider,
│                                       PresignedS3UrlProvider, ImageInspector, ResultDownloader, InputUploadService
├── app/Services/Generation/            FourKRule, InputComposer, CreateGeneration, RestartGeneration, GenerationStateMachine
├── app/Jobs/                           RunGenerationJob, PollGenerationJob, DownloadOutputJob
├── app/Console/Commands/               MediaReconcile, CleanUnreferencedInputs, KreaSmoke
├── app/Filament/Admin/Resources/       BrandResource, UserResource, CampaignResource (+ RelationManagers), PipelineResource
├── app/Filament/App/Pages/             Campaigns, Generator, Gallery, Settings
├── app/Livewire/                       PieceViewer, JobsBell
├── app/Providers/Filament/             AdminPanelProvider, AppPanelProvider
├── config/media.php                    url_provider, ttl, limits, result hosts
├── database/migrations/                one migration per table, in dependency order
├── database/factories/                 one factory per model
├── docker-compose.yml                  MinIO
├── tests/Unit/                         pure services
├── tests/Feature/                      chain, authorization, resources
└── tests/Fixtures/krea/                sanitized real responses (Task 24)
```

---

## Phase 0 — Tooling and scaffold

### Task 0: Codex setup and tooling check

**Files:** none in repo.

- [ ] **Step 1: Run the Codex setup skill** (user instruction): invoke `/codex:setup` and confirm the local Codex CLI is ready.
- [ ] **Step 2: Check tool versions**

Run (from repo root):
```bash
php -v | head -1; composer --version; node -v; docker info --format '{{.ServerVersion}}'; mysql --version
```
Expected: PHP 8.2.x, Node 22.x, Docker daemon version printed (not an error), MySQL client present. Composer will print 2.1.5.

- [ ] **Step 3: Upgrade Composer to 2.8+ without overwriting the system binary if not permitted**

Run:
```bash
composer self-update --2 || { mkdir -p "$HOME/bin" && php -r "copy('https://getcomposer.org/installer','/tmp/cs.php');" && php /tmp/cs.php --install-dir="$HOME/bin" --filename=composer && echo 'export PATH="$HOME/bin:$PATH"' >> ~/.zshrc && export PATH="$HOME/bin:$PATH"; }
composer --version
```
Expected: `Composer version 2.8.x` or newer.

- [ ] **Step 4: Verify MySQL is reachable and create dev/test databases**

Run:
```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS muse CHARACTER SET utf8mb4; CREATE DATABASE IF NOT EXISTS muse_test CHARACTER SET utf8mb4; SHOW DATABASES LIKE 'muse%';"
```
Expected: two rows, `muse` and `muse_test`. If root needs a password, ask the user for the local MySQL credentials.

### Task 1: Laravel 12 scaffold with Pest, Filament 5, two panels, Boost, Blueprint

**Files:**
- Create: `app/` (Laravel project), `app/.env`, `app/phpunit.xml` (edit), `app/app/Providers/Filament/AdminPanelProvider.php`, `app/app/Providers/Filament/AppPanelProvider.php`
- Repo root: `.gitignore` (add `app/vendor/`, `app/node_modules/`, `app/.env`, `app/storage/*.key`, `app/public/build/`)

- [ ] **Step 1: Create the Laravel app** (repo root)

```bash
composer create-project laravel/laravel:^12.0 app
cd app
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
php artisan pest:install
```
Expected: `tests/Pest.php` exists; `./vendor/bin/pest` runs the two example tests green. Then edit `tests/Pest.php` so every test boots the app:
```php
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature', 'Unit');
```

- [ ] **Step 2: Point the app and tests at MySQL**

Edit `.env`:
```
APP_NAME="Media Ops"
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=muse
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
FILESYSTEM_DISK=inputs
```
Edit `phpunit.xml` `<php>` block: set `DB_CONNECTION=mysql`, `DB_DATABASE=muse_test`, `QUEUE_CONNECTION=sync`, `FILESYSTEM_DISK=inputs`, `MEDIA_URL_PROVIDER=presigned`. Remove the sqlite lines.

Run: `php artisan migrate && ./vendor/bin/pest`
Expected: default migrations applied to `muse`; tests green against `muse_test` (RefreshDatabase).

- [ ] **Step 3: Install Filament 5 and create both panels**

```bash
composer require filament/filament:"^5.0" -W
php artisan filament:install --panels        # creates AdminPanelProvider with id 'admin', path 'admin'
php artisan make:filament-panel app          # creates AppPanelProvider with id 'app', path 'app'
npm install && npm run build
```
Expected: `composer show livewire/livewire` reports `v4.x`; `composer show filament/filament` reports `v5.x`. Both providers are registered in `bootstrap/providers.php`.

- [ ] **Step 4: Install Boost and Blueprint (dev)**

```bash
composer require laravel/boost --dev
php artisan boost:install
composer config repositories.filament composer https://packages.filamentphp.com/composer
# The USER runs the next line themselves (do not paste the key into chat):
#   composer config --auth http-basic.packages.filamentphp.com "EMAIL" "LICENSE_KEY"
composer require filament/blueprint --dev
```
Expected: `boost:install` generates `CLAUDE.md`/`AGENTS.md` guidelines in `app/`; `filament/blueprint` appears in `composer.json` `require-dev`. If auth fails, stop and ask the user to run the auth command.

- [ ] **Step 5: Ignore generated files at repo root**

Append to repo-root `.gitignore`:
```
app/vendor/
app/node_modules/
app/.env
app/.env.*.local
app/public/build/
app/public/hot
app/storage/*.key
app/auth.json
```

- [ ] **Step 6: Commit**

```bash
cd .. && git add -A && git commit -m "chore: scaffold Laravel 12 app with Filament 5 panels, Pest, Boost and Blueprint"
```

### Task 2: MinIO, object-storage disks, media config

**Files:**
- Create: `app/docker-compose.yml`, `app/config/media.php`
- Modify: `app/config/filesystems.php`, `app/config/livewire.php` (publish first), `app/.env`, `app/.env.example`

- [ ] **Step 1: Compose file for MinIO**

`app/docker-compose.yml`:
```yaml
services:
  minio:
    image: minio/minio:latest
    command: server /data --console-address ":9001"
    ports: ["9000:9000", "9001:9001"]
    environment:
      MINIO_ROOT_USER: muse
      MINIO_ROOT_PASSWORD: muse-secret
    volumes: [minio-data:/data]
  minio-init:
    image: minio/mc:latest
    depends_on: [minio]
    entrypoint: >
      /bin/sh -c "
      until mc alias set local http://minio:9000 muse muse-secret; do sleep 1; done;
      mc mb -p local/muse-media || true;
      mc ilm rule add --expire-days 1 --prefix 'inputs/tmp/' local/muse-media || true;
      exit 0"
volumes:
  minio-data: {}
```
Run: `docker compose up -d && docker compose logs minio-init | tail -2`
Expected: bucket `muse-media` created; lifecycle rule on `inputs/tmp/` added.

- [ ] **Step 2: Disks and env**

Add to `config/filesystems.php` `disks`:
```php
'inputs' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'root' => 'inputs',
    'visibility' => 'private',
    'throw' => true,
],
'pieces' => [
    // identical to 'inputs' except:
    'root' => 'pieces',
],
```
`.env` additions (and `.env.example` with empty values):
```
AWS_ACCESS_KEY_ID=muse
AWS_SECRET_ACCESS_KEY=muse-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=muse-media
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
MEDIA_URL_PROVIDER=presigned
MEDIA_SIGNED_URL_TTL=600
CLOUDFRONT_DOMAIN=
CLOUDFRONT_KEY_PAIR_ID=
CLOUDFRONT_PRIVATE_KEY_PATH=
KREA_API_KEY=
KREA_BASE_URL=https://api.krea.ai
```
Run: `composer require league/flysystem-aws-s3-v3 "^3.0" -W`

- [ ] **Step 3: Livewire temporary uploads on S3**

```bash
php artisan livewire:publish --config
```
Edit `config/livewire.php`:
```php
'temporary_file_upload' => [
    'disk' => 'inputs',
    'rules' => ['required', 'file', 'image', 'max:20480'],
    'directory' => 'tmp',
    'middleware' => null,
    'preview_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
    'max_upload_time' => 5,
    'cleanup' => true,
],
```

- [ ] **Step 3b: Queue budgets** — in `config/queue.php` set `'retry_after' => 420` on both the `database` and `redis` connections (Global Constraints).

- [ ] **Step 4: `config/media.php`**

```php
<?php

return [
    'url_provider' => env('MEDIA_URL_PROVIDER', 'presigned'), // presigned | cloudfront
    'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 600),
    'max_upload_kb' => 20480,
    'max_request_bytes' => 96 * 1024 * 1024,
    'max_result_bytes' => 64 * 1024 * 1024,
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'cloudfront' => [
        'domain' => env('CLOUDFRONT_DOMAIN'),
        'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID'),
        'private_key_path' => env('CLOUDFRONT_PRIVATE_KEY_PATH'),
    ],
    'krea' => [
        'base_url' => env('KREA_BASE_URL', 'https://api.krea.ai'),
        'key' => env('KREA_API_KEY'),
    ],
];
```

- [ ] **Step 5: Smoke test the disk against MinIO**

Run: `php artisan tinker --execute="Storage::disk('inputs')->put('tmp/ping.txt','ok'); echo Storage::disk('inputs')->get('tmp/ping.txt');"`
Expected: prints `ok`.

- [ ] **Step 6: Commit** — `git commit -m "chore: MinIO compose, S3 disks, Livewire S3 temp uploads, media config"`

---

## Phase 1 — Enums, models, migrations, factories

### Task 3: Enums

**Files:** Create `app/app/Enums/*.php` (10 files). **Test:** `tests/Unit/Enums/GenerationStatusTest.php`

**Interfaces — Produces:** backed string enums used everywhere below:
`UserRole {ArtDirector='art_director', Editor='editor'}`, `PipelineKind {Generator='generator', Editor='editor', Upscaler='upscaler'}`, `InputType {String='string', Image='image', Integer='integer', Number='number', Boolean='boolean', Unknown='unknown'}`, `FieldRole {Prompt='prompt', Image='image', None='none'}`, `FieldVisibility {Visible='visible', Hidden='hidden'}`, `GenerationKind {Series='series', Edit='edit', Upscale='upscale'}`, `GenerationStatus {Pending, Submitting, Submitted, Processing, Downloading, Completed, Failed}` (lowercase values) with `isTerminal(): bool`, `FailureReason {SubmissionUnknown='submission_unknown', PollTimeout='poll_timeout', ProviderFailed='provider_failed', InvalidResult='invalid_result', DeliveryDimensions='delivery_dimensions', DownloadFailed='download_failed'}`, `PieceKind {Original='original', Edit='edit', Upscale='upscale'}`, `OutputStatus {Pending='pending', Downloading='downloading', Stored='stored', Failed='failed'}`.

- [ ] **Step 1: Failing test**

`tests/Unit/Enums/GenerationStatusTest.php`:
```php
<?php

use App\Enums\GenerationStatus;

it('marks only completed and failed as terminal', function () {
    expect(GenerationStatus::Completed->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Failed->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Pending->isTerminal())->toBeFalse()
        ->and(GenerationStatus::Submitting->isTerminal())->toBeFalse()
        ->and(GenerationStatus::Downloading->isTerminal())->toBeFalse();
});
```
Run: `./vendor/bin/pest tests/Unit/Enums` → FAIL (class not found).

- [ ] **Step 2: Implement** `app/Enums/GenerationStatus.php`:
```php
<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Submitting = 'submitting';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    /** @return list<string> */
    public static function nonTerminalValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => ! $s->isTerminal()));
    }
}
```
Create the other nine enums with the exact cases and values listed in Interfaces above (plain backed enums, no methods).

- [ ] **Step 3: Run** `./vendor/bin/pest tests/Unit/Enums` → PASS.
- [ ] **Step 4: Commit** — `git commit -m "feat: domain enums"`

### Task 4: Migrations and models — brands, users, campaigns, pipelines, pipeline_fields

**Files:**
- Create migrations: `create_brands_table`, `add_role_to_users_table`, `create_brand_user_table`, `create_campaigns_table`, `create_pipelines_table`, `create_pipeline_fields_table`
- Create models: `app/Models/Brand.php`, `Campaign.php`, `Pipeline.php`, `PipelineField.php`; modify `User.php`
- Factories for each. **Test:** `tests/Feature/Models/CoreModelsTest.php`

**Interfaces — Produces:**
- `Brand`: `users()` belongsToMany, `campaigns()` hasMany, `krea_api_key` encrypted cast, `resolveKreaKeySource(): 'brand'|'studio'`.
- `User`: `role` cast `UserRole`, `brands()` belongsToMany, `isArtDirector(): bool`.
- `Campaign`: `brand()`, `pipelines()`, `generations()`, `pieces()`, `defaultPipeline()`, `activeGenerators()` (ordered by `sort_order`), `activeEditor(): ?Pipeline`, `activeUpscaler(): ?Pipeline`, soft deletes.
- `Pipeline`: `campaign()`, `fields()` (ordered), `kind` cast `PipelineKind`, `input_schema`/`readiness_errors` json casts, `isReady(): bool` (= `is_active && empty(readiness_errors)`), `activeFields()` (non-stale).
- `PipelineField`: casts `input_type`, `visibility`, `role`, `source_schema` json, `fixed_value` json, `has_fixed_value` bool, `stale` bool, `needs_configuration` bool.

- [ ] **Step 1: Failing test**

`tests/Feature/Models/CoreModelsTest.php`:
```php
<?php

use App\Enums\PipelineKind;
use App\Models\{Brand, Campaign, Pipeline, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links editors to brands and exposes active pipelines by kind', function () {
    $brand = Brand::factory()->create(['krea_api_key' => 'secret-key']);
    $editor = User::factory()->editor()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create();
    $gen = Pipeline::factory()->for($campaign)->create(['kind' => PipelineKind::Generator, 'is_active' => true, 'sort_order' => 2]);
    $gen1 = Pipeline::factory()->for($campaign)->create(['kind' => PipelineKind::Generator, 'is_active' => true, 'sort_order' => 1]);
    Pipeline::factory()->for($campaign)->create(['kind' => PipelineKind::Editor, 'is_active' => true]);
    Pipeline::factory()->for($campaign)->create(['kind' => PipelineKind::Upscaler, 'is_active' => false]);

    expect($editor->fresh()->brands)->toHaveCount(1)
        ->and($campaign->activeGenerators()->pluck('id')->all())->toBe([$gen1->id, $gen->id])
        ->and($campaign->activeEditor())->not->toBeNull()
        ->and($campaign->activeUpscaler())->toBeNull()
        ->and($brand->resolveKreaKeySource())->toBe('brand')
        ->and(DB::table('brands')->where('id', $brand->id)->value('krea_api_key'))->not->toBe('secret-key');
});
```
Run → FAIL.

- [ ] **Step 2: Migrations** (`php artisan make:migration …` then fill):

```php
// create_brands_table
Schema::create('brands', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->string('slug')->unique();
    $t->string('logo_path')->nullable(); $t->text('krea_api_key')->nullable(); $t->timestamps();
});
// add_role_to_users_table
Schema::table('users', fn (Blueprint $t) => $t->string('role', 20)->default('editor')->after('password'));
// create_brand_user_table
Schema::create('brand_user', function (Blueprint $t) {
    $t->id(); $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->timestamps();
    $t->unique(['brand_id', 'user_id']);
});
// create_campaigns_table
Schema::create('campaigns', function (Blueprint $t) {
    $t->id(); $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
    $t->string('name'); $t->string('slug'); $t->text('description')->nullable();
    $t->string('cover_path')->nullable(); $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
    $t->unsignedBigInteger('default_pipeline_id')->nullable(); $t->timestamps(); $t->softDeletes();
    $t->unique(['brand_id', 'slug']);
});
// create_pipelines_table
Schema::create('pipelines', function (Blueprint $t) {
    $t->id(); $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
    $t->string('kind', 20); $t->string('engine', 20)->default('krea'); $t->string('provider_ref');
    $t->string('label'); $t->json('input_schema')->nullable(); $t->timestamp('schema_fetched_at')->nullable();
    $t->unsignedInteger('config_revision')->default(1); $t->json('readiness_errors')->nullable();
    $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_active')->default(false); $t->timestamps();
});
// create_pipeline_fields_table
Schema::create('pipeline_fields', function (Blueprint $t) {
    $t->id(); $t->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
    $t->string('name'); $t->json('source_schema'); $t->string('input_type', 20); $t->boolean('required')->default(false);
    $t->string('label_override')->nullable(); $t->string('help_text')->nullable();
    $t->string('visibility', 10)->default('visible'); $t->boolean('has_fixed_value')->default(false);
    $t->json('fixed_value')->nullable(); $t->string('role', 10)->default('none'); $t->boolean('stale')->default(false);
    $t->boolean('needs_configuration')->default(false); // set by sync for new required or type-changed fields; cleared when an Art Director saves the field
    $t->unsignedInteger('sort_order')->default(0); $t->timestamps();
    $t->unique(['pipeline_id', 'name']);
});
```
Add `$t->foreign('default_pipeline_id')->references('id')->on('pipelines')->nullOnDelete();` in a small follow-up migration created after `pipelines`.

- [ ] **Step 3: Models**

`app/Models/Brand.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsToMany, HasMany};

class Brand extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'logo_path', 'krea_api_key'];
    protected $casts = ['krea_api_key' => 'encrypted'];
    protected $hidden = ['krea_api_key'];

    public function users(): BelongsToMany { return $this->belongsToMany(User::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }

    public function resolveKreaKeySource(): string
    {
        return filled($this->krea_api_key) ? 'brand' : 'studio';
    }
}
```
`User` additions: `use App\Enums\UserRole;` cast `'role' => UserRole::class`, `brands(): BelongsToMany`, `isArtDirector(): bool { return $this->role === UserRole::ArtDirector; }`. (Filament interfaces are added in Task 17/18.)

`Campaign`:
```php
class Campaign extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = ['brand_id','name','slug','description','cover_path','starts_on','ends_on','default_pipeline_id'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function pipelines(): HasMany { return $this->hasMany(Pipeline::class)->orderBy('sort_order'); }
    public function generations(): HasMany { return $this->hasMany(Generation::class); }
    public function pieces(): HasMany { return $this->hasMany(Piece::class); }
    public function defaultPipeline(): BelongsTo { return $this->belongsTo(Pipeline::class, 'default_pipeline_id'); }
    public function activeGenerators(): HasMany { return $this->pipelines()->where('kind', PipelineKind::Generator)->where('is_active', true); }
    public function activeEditor(): ?Pipeline { return $this->pipelines()->where('kind', PipelineKind::Editor)->where('is_active', true)->first(); }
    public function activeUpscaler(): ?Pipeline { return $this->pipelines()->where('kind', PipelineKind::Upscaler)->where('is_active', true)->first(); }
}
```
`Pipeline`:
```php
class Pipeline extends Model
{
    use HasFactory;
    protected $fillable = ['campaign_id','kind','engine','provider_ref','label','input_schema','schema_fetched_at','config_revision','readiness_errors','sort_order','is_active'];
    protected $casts = ['kind' => PipelineKind::class, 'input_schema' => 'array', 'readiness_errors' => 'array', 'schema_fetched_at' => 'datetime', 'is_active' => 'bool'];

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function fields(): HasMany { return $this->hasMany(PipelineField::class)->orderBy('sort_order'); }
    public function activeFields(): HasMany { return $this->fields()->where('stale', false); }
    public function isReady(): bool { return $this->is_active && empty($this->readiness_errors); }
}
```
`PipelineField`: fillable all columns; casts `input_type => InputType::class`, `visibility => FieldVisibility::class`, `role => FieldRole::class`, `source_schema => 'array'`, `fixed_value => 'json'`, `has_fixed_value => 'bool'`, `required => 'bool'`, `stale => 'bool'`; `pipeline(): BelongsTo`.

- [ ] **Step 4: Factories** — `BrandFactory` (`name` company, `slug` from name), `UserFactory` add state `editor()` (`role => 'editor'`) and `artDirector()`, `CampaignFactory` (`brand_id` factory, `name`, `slug`), `PipelineFactory` (`campaign_id` factory, `kind => 'generator'`, `provider_ref => fake()->uuid()`, `label`, `is_active => false`), `PipelineFieldFactory` (`name`, `source_schema => ['type' => 'string']`, `input_type => 'string'`).

- [ ] **Step 5: Run** `php artisan migrate:fresh && ./vendor/bin/pest tests/Feature/Models` → PASS.
- [ ] **Step 6: Commit** — `git commit -m "feat: brands, campaigns, pipelines, pipeline fields models and migrations"`

### Task 5: Migrations and models — input_uploads, generations, generation_jobs, generation_outputs, pieces

**Files:** migrations `create_input_uploads_table`, `create_generation_inputs_table`, `create_pipeline_inputs_table`, `create_generations_table`, `create_generation_jobs_table`, `create_generation_outputs_table`, `create_pieces_table`; models `InputUpload`, `Generation`, `GenerationJob`, `GenerationOutput`, `Piece`; factories. **Test:** `tests/Feature/Models/GenerationModelsTest.php`

**Interfaces — Produces:**
- `Generation`: casts `kind => GenerationKind`, `status => GenerationStatus`, `failure_reason => FailureReason` (nullable), `execution_snapshot => array`, `retryable => bool`, timestamps `submission_started_at`, `submitted_at`, `completed_at`, `seen_at`; relations `campaign()`, `pipeline()`, `user()`, `parentPiece()`, `jobs()`, `outputs()`, `pieces()`, `inputUploads()` (belongsToMany via `generation_inputs`), `restartedFrom()`; scope `nonTerminal()`.
- `GenerationJob`: `generation()`, `outputs()`; casts `result`, `error` arrays; `isTerminal(): bool` (normalized_status in completed/failed/cancelled).
- `GenerationOutput`: `generation()`, `job()`, `piece()`; `status => OutputStatus`. Identity is `(generation_job_id, index)`: replaying a completed job upserts the same rows.
- `Piece`: casts `kind => PieceKind`, `is_4k`, `selected` bool; relations `generation()`, `output()`, `campaign()`, `parent()`, `root()`; `versionChain(): Collection` = root + all pieces with `root_piece_id = rootId`, ordered `created_at, id`; `rootId(): int` = `root_piece_id ?? id`.
- `InputUpload`: `brand()`, `user()`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\PieceKind;
use App\Models\{Campaign, Generation, Piece};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a version chain from root through edits and upscales', function () {
    $campaign = Campaign::factory()->create();
    $gen = Generation::factory()->for($campaign)->create();
    $root = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Original]);
    $edit = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Edit, 'parent_piece_id' => $root->id, 'root_piece_id' => $root->rootId()]);
    $up = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Upscale, 'parent_piece_id' => $edit->id, 'root_piece_id' => $edit->rootId()]);
    $edit2 = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Edit, 'parent_piece_id' => $up->id, 'root_piece_id' => $up->rootId()]);

    expect($up->root_piece_id)->toBe($root->id)
        ->and($edit2->versionChain()->pluck('id')->all())->toBe([$root->id, $edit->id, $up->id, $edit2->id]);
});
```
Run → FAIL.

- [ ] **Step 2: Migrations**

```php
Schema::create('input_uploads', function (Blueprint $t) {
    $t->id(); $t->foreignId('brand_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained();
    $t->string('storage_path')->unique(); $t->string('mime_type', 50); $t->unsignedBigInteger('bytes');
    $t->unsignedInteger('width'); $t->unsignedInteger('height'); $t->timestamp('finalized_at')->nullable(); $t->timestamps();
});
Schema::create('generations', function (Blueprint $t) {
    $t->id(); $t->foreignId('campaign_id')->constrained()->cascadeOnDelete(); $t->foreignId('pipeline_id')->constrained();
    $t->foreignId('user_id')->constrained(); $t->string('kind', 10); $t->unsignedBigInteger('parent_piece_id')->nullable();
    $t->uuid('request_id')->unique(); $t->json('execution_snapshot'); $t->string('status', 15)->default('pending');
    $t->string('failure_reason', 30)->nullable(); $t->text('error_message')->nullable(); $t->boolean('retryable')->default(false);
    $t->foreignId('restarted_from_generation_id')->nullable()->constrained('generations')->nullOnDelete();
    $t->timestamp('submission_started_at')->nullable(); $t->timestamp('submitted_at')->nullable();
    $t->timestamp('completed_at')->nullable(); $t->timestamp('seen_at')->nullable(); $t->timestamps();
    $t->index(['campaign_id', 'status']); $t->index(['user_id', 'status']);
});
Schema::create('generation_inputs', function (Blueprint $t) {
    $t->foreignId('generation_id')->constrained()->cascadeOnDelete(); $t->foreignId('input_upload_id')->constrained();
    $t->primary(['generation_id', 'input_upload_id']);
});
Schema::create('pipeline_inputs', function (Blueprint $t) {
    $t->foreignId('pipeline_id')->constrained()->cascadeOnDelete(); $t->foreignId('input_upload_id')->constrained();
    $t->primary(['pipeline_id', 'input_upload_id']);
});
Schema::create('generation_jobs', function (Blueprint $t) {
    $t->id(); $t->foreignId('generation_id')->constrained()->cascadeOnDelete(); $t->string('provider_job_id');
    $t->string('status', 40)->nullable(); $t->string('normalized_status', 15)->default('pending');
    $t->unsignedInteger('queue_position')->nullable(); $t->json('result')->nullable(); $t->json('error')->nullable();
    $t->timestamp('last_polled_at')->nullable(); $t->timestamp('next_poll_at')->nullable();
    $t->unsignedInteger('poll_failures')->default(0); $t->timestamps();
    $t->unique(['generation_id', 'provider_job_id']);
});
Schema::create('generation_outputs', function (Blueprint $t) {
    $t->id(); $t->foreignId('generation_id')->constrained()->cascadeOnDelete();
    $t->foreignId('generation_job_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('index');
    $t->text('source_url'); $t->string('status', 12)->default('pending'); $t->unsignedInteger('attempts')->default(0);
    $t->timestamp('next_attempt_at')->nullable(); $t->text('error_message')->nullable(); $t->timestamps();
    $t->unique(['generation_job_id', 'index']); // index = position inside that job's result; stable under replay
});
Schema::create('pieces', function (Blueprint $t) {
    $t->id(); $t->foreignId('generation_id')->constrained()->cascadeOnDelete();
    $t->foreignId('generation_output_id')->unique()->constrained()->cascadeOnDelete();
    $t->foreignId('campaign_id')->constrained()->cascadeOnDelete(); $t->string('kind', 10);
    $t->foreignId('parent_piece_id')->nullable()->constrained('pieces')->nullOnDelete();
    $t->foreignId('root_piece_id')->nullable()->constrained('pieces')->nullOnDelete();
    $t->string('storage_path'); $t->text('source_url'); $t->unsignedInteger('width'); $t->unsignedInteger('height');
    $t->unsignedBigInteger('bytes'); $t->string('mime_type', 50); $t->unsignedInteger('index');
    $t->boolean('is_4k')->default(false); $t->boolean('selected')->default(false); $t->timestamps();
    $t->index(['campaign_id', 'kind']); $t->index('root_piece_id');
});
```
Then a migration adding `$t->foreign('parent_piece_id')->references('id')->on('pieces')->nullOnDelete();` to `generations`.

- [ ] **Step 3: Models** — `Piece`:
```php
public function rootId(): int { return $this->root_piece_id ?? $this->id; }

public function versionChain(): \Illuminate\Support\Collection
{
    $rootId = $this->rootId();
    return static::query()
        ->where(fn ($q) => $q->whereKey($rootId)->orWhere('root_piece_id', $rootId))
        ->orderBy('created_at')->orderBy('id')->get();
}
```
`Generation` scope: `public function scopeNonTerminal($q) { return $q->whereIn('status', GenerationStatus::nonTerminalValues()); }`. Fill the other relations exactly as listed in Interfaces.

- [ ] **Step 4: Factories** — `GenerationFactory` (`campaign_id`, `pipeline_id` from campaign via `afterMaking` or explicit, `user_id`, `kind => 'series'`, `request_id => Str::uuid()`, `execution_snapshot => []`, `status => 'pending'`); `GenerationJobFactory`, `GenerationOutputFactory` (`index` sequence, `source_url => 'https://cdn.example/img.png'`), `PieceFactory` (creates a `GenerationOutput` for `generation_output_id`, `storage_path`, `source_url`, `width 1024`, `height 1024`, `bytes 1000`, `mime_type image/png`, `index 0`, `kind original`), `InputUploadFactory`.

- [ ] **Step 5: Run** `php artisan migrate:fresh && ./vendor/bin/pest tests/Feature/Models` → PASS.
- [ ] **Step 6: Commit** — `git commit -m "feat: uploads, generations, jobs, outputs, pieces models and migrations"`

---

## Phase 2 — Engine layer

### Task 6: ImageEngine interface, DTOs, KreaException, FakeEngine

**Files:**
- Create: `app/app/Engines/ImageEngine.php`, `app/app/Engines/KreaException.php`, `app/app/Engines/FakeEngine.php`, `app/app/Engines/Data/EngineSchema.php`, `SubmissionOutcome.php`, `JobObservation.php`, `OutputRef.php`
- Test: `tests/Unit/Engines/FakeEngineTest.php`

**Interfaces — Produces:**
```php
interface ImageEngine {
    public function describe(string $providerRef): EngineSchema;
    /** @param array<string,mixed> $inputs */
    public function submit(string $providerRef, array $inputs): SubmissionOutcome;
    public function inspect(string $jobId): JobObservation;
    /** @return list<OutputRef> */
    public function outputs(array $result): array;
}
final readonly class EngineSchema { public function __construct(public string $name, public string $versionId, public ?array $inputSchema) {} }
final readonly class SubmissionOutcome {
    private function __construct(public string $state, public array $jobIds = [], public ?string $error = null, public ?int $httpStatus = null) {}
    public static function accepted(array $jobIds): self; public static function rejected(string $error, ?int $status = null): self; public static function unknown(string $error): self;
    public function isAccepted(): bool; public function isRejected(): bool; public function isUnknown(): bool;
}
final readonly class JobObservation { public function __construct(public string $normalizedStatus /* pending|completed|failed|cancelled */, public string $nativeStatus, public ?int $queuePosition, public ?array $result, public ?array $error) {} public function isTerminal(): bool; }
final readonly class OutputRef { public function __construct(public int $index, public string $url) {} }
class KreaException extends \RuntimeException { public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly ?string $detail = null) }
```
`FakeEngine` is a test double with recording: `withSchema(string $ref, ?array $schema)`, `willAccept(array $jobIds)`, `willReject(string $msg, int $status)`, `willBeUnknown()`, `setJob(string $jobId, JobObservation $obs)`, `submissions: list<array{ref:string, inputs:array}>`, `outputs()` implements the same flattening as `KreaEngine` (Task 7) by delegating to a shared `ResultFlattener` helper.

- [ ] **Step 1: Failing test**

`tests/Unit/Engines/FakeEngineTest.php`:
```php
<?php

use App\Engines\Data\JobObservation;
use App\Engines\FakeEngine;

it('records submissions and returns programmed outcomes', function () {
    $engine = new FakeEngine();
    $engine->willAccept(['job-1', 'job-2']);
    $engine->setJob('job-1', new JobObservation('completed', 'completed', null, ['urls' => ['https://x/a.png']], null));

    $out = $engine->submit('ver-1', ['prompt' => 'hola']);

    expect($out->isAccepted())->toBeTrue()->and($out->jobIds)->toBe(['job-1', 'job-2'])
        ->and($engine->submissions)->toHaveCount(1)
        ->and($engine->inspect('job-1')->isTerminal())->toBeTrue()
        ->and($engine->inspect('job-2')->normalizedStatus)->toBe('pending');
});

it('flattens result urls deterministically and deduplicates', function () {
    $refs = (new FakeEngine())->outputs(['urls' => ['https://x/a.png', ['https://x/b.png', 'https://x/a.png'], 'k' => 'https://x/c.png']]);
    expect(array_map(fn ($r) => [$r->index, $r->url], $refs))->toBe([[0, 'https://x/a.png'], [1, 'https://x/b.png'], [2, 'https://x/c.png']]);
});
```
Run → FAIL.

- [ ] **Step 2: Implement** the DTOs exactly as in Interfaces; `JobObservation::isTerminal()` returns `in_array($this->normalizedStatus, ['completed','failed','cancelled'], true)`.

`app/Engines/ResultFlattener.php`:
```php
<?php

namespace App\Engines;

use App\Engines\Data\OutputRef;

final class ResultFlattener
{
    /** @return list<OutputRef> */
    public static function flatten(array $result): array
    {
        $urls = [];
        $walk = function ($node) use (&$walk, &$urls): void {
            if (is_string($node)) { if (preg_match('#^https://#i', $node) && ! in_array($node, $urls, true)) { $urls[] = $node; } return; }
            if (is_array($node)) { foreach ($node as $child) { $walk($child); } }
        };
        $walk($result['urls'] ?? []);
        return array_values(array_map(fn (int $i, string $u) => new OutputRef($i, $u), array_keys($urls), $urls));
    }
}
```
`FakeEngine`:
```php
final class FakeEngine implements ImageEngine
{
    public array $submissions = [];
    private array $schemas = [];
    private ?SubmissionOutcome $nextOutcome = null;
    private array $jobs = [];

    public function withSchema(string $ref, ?array $schema, string $name = 'Fake app'): self { $this->schemas[$ref] = new EngineSchema($name, $ref, $schema); return $this; }
    public function willAccept(array $jobIds): self { $this->nextOutcome = SubmissionOutcome::accepted($jobIds); return $this; }
    public function willReject(string $msg, int $status = 400): self { $this->nextOutcome = SubmissionOutcome::rejected($msg, $status); return $this; }
    public function willBeUnknown(): self { $this->nextOutcome = SubmissionOutcome::unknown('timeout'); return $this; }
    public function setJob(string $jobId, JobObservation $obs): self { $this->jobs[$jobId] = $obs; return $this; }

    public function describe(string $providerRef): EngineSchema
    { return $this->schemas[$providerRef] ?? throw new KreaException('No se encontró el flujo.', 404); }
    public function submit(string $providerRef, array $inputs): SubmissionOutcome
    { $this->submissions[] = ['ref' => $providerRef, 'inputs' => $inputs]; return $this->nextOutcome ?? SubmissionOutcome::accepted(['job-' . count($this->submissions)]); }
    public function inspect(string $jobId): JobObservation
    { return $this->jobs[$jobId] ?? new JobObservation('pending', 'queued', null, null, null); }
    public function outputs(array $result): array { return ResultFlattener::flatten($result); }
}
```

- [ ] **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: ImageEngine contract, DTOs and FakeEngine"`

### Task 6b: Gate A — real Krea schemas, no spend

**Files:** Create `tests/Fixtures/krea/schema-{generator,editor,upscaler}.json` (sanitized), `docs/superpowers/research/2026-09-XX-gate-a-schemas.md`.

Preconditions: the fresh Krea key is in `.env` (`KREA_API_KEY`) and the user has given the real version IDs. Do not proceed to Task 7 without them; ask once.

- [ ] **Step 1: Fetch each schema with a throwaway HTTP call** (no app code yet):
```bash
for id in GENERATOR_ID EDITOR_ID UPSCALER_ID; do
  curl -sS -H "Authorization: Bearer $(grep ^KREA_API_KEY .env | cut -d= -f2)" "https://api.krea.ai/node-apps/$id" | python3 -m json.tool > "tests/Fixtures/krea/schema-$id.json"
done
```
Rename the files to `schema-generator.json`, `schema-editor.json`, `schema-upscaler.json`. Open each and confirm no key or personal data is present; remove `example_outputs` URLs if they embed tokens.
- [ ] **Step 2: Record findings** in the research note: property names and types per app, which properties are images, whether the upscaler exposes `width`/`height`, `scale`, or nothing, any `null` schema, and anything outside the supported subset (§4.2 of the spec). These names drive Tasks 9–11 and 14.
- [ ] **Step 3: Commit** — `git commit -m "test: Gate A real Krea schemas (sanitized)"`

### Task 7: KreaEngine over Laravel HTTP client, EngineResolver

**Files:**
- Create: `app/app/Engines/Krea/KreaEngine.php`, `app/app/Engines/EngineResolver.php`, `app/app/Engines/KreaErrorMessages.php`
- Modify: `app/app/Providers/AppServiceProvider.php` (bind `ImageEngine` via resolver factory)
- Test: `tests/Unit/Engines/KreaEngineTest.php`, `tests/Unit/Engines/KreaErrorMessagesTest.php`

**Interfaces — Produces:**
- `new KreaEngine(string $apiKey, string $baseUrl = 'https://api.krea.ai')`.
- `EngineResolver::forBrand(Brand $brand): ImageEngine` and `EngineResolver::forSource(Brand $brand, string $source): ImageEngine` — `source` is `brand` or `studio`; throws `KreaException('Clave de acceso inválida o faltante.', 401)` if neither key exists. In tests `EngineResolver` returns the bound `FakeEngine` when `app()->bound(FakeEngine::class)`.
- `KreaErrorMessages::forStatus(?int $status, ?string $detail): string` (applies `sanitizeDetail` to `$detail`), `KreaErrorMessages::network(): string`, and `KreaErrorMessages::sanitizeDetail(?string $detail): ?string` — removes `data:` URLs, bearer tokens, and any token of 24+ URL-safe characters that mixes letters and digits (key-shaped), then truncates to 2048 bytes. Every provider error stored on a row or logged goes through it.
- `KreaEngine::describe()` wraps `ConnectionException` into `KreaException(KreaErrorMessages::network(), null)` so callers (Task 10 sync, Task 19 admin) see one exception type.

- [ ] **Step 1: Failing tests**

`tests/Unit/Engines/KreaErrorMessagesTest.php`:
```php
<?php

use App\Engines\KreaErrorMessages;

it('maps krea http statuses to spanish messages', function (?int $status, string $expected) {
    expect(KreaErrorMessages::forStatus($status, null))->toBe($expected);
})->with([
    [400, 'Solicitud inválida.'], [401, 'Clave de acceso inválida o faltante.'], [402, 'Saldo insuficiente.'],
    [404, 'No se encontró el flujo.'], [429, 'El servicio está saturado. Intenta en unos minutos.'],
    [500, 'Error interno del servicio.'], [503, 'Error interno del servicio.'], [418, 'Error 418.'],
]);

it('appends detail and exposes the network message', function () {
    expect(KreaErrorMessages::forStatus(400, 'campo x'))->toBe('Solicitud inválida. campo x')
        ->and(KreaErrorMessages::network())->toBe('No se pudo conectar con el servicio. Intenta de nuevo en unos segundos.');
});

it('redacts key-shaped tokens and data urls and truncates to 2 KiB', function () {
    $detail = 'bad key 52ce52f9e9ba4ad49c9250a65138a356pXPP0L9k and data:image/png;base64,AAAA and ' . str_repeat('x', 3000);
    $out = KreaErrorMessages::sanitizeDetail($detail);
    expect($out)->not->toContain('52ce52f9')->not->toContain('data:image')->and(strlen($out))->toBeLessThanOrEqual(2048);
});
```
`tests/Unit/Engines/KreaEngineTest.php`:
```php
<?php

use App\Engines\Krea\KreaEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->engine = new KreaEngine('k-123', 'https://api.krea.test'));

it('describes a node app version', function () {
    Http::fake(['https://api.krea.test/node-apps/ver-1' => Http::response(['name' => 'Creador', 'node_app_version_id' => 'ver-1', 'input_openapi_schema' => ['type' => 'object', 'properties' => ['describe_la_escena' => ['type' => 'string']], 'required' => ['describe_la_escena']]])]);
    $s = $this->engine->describe('ver-1');
    expect($s->name)->toBe('Creador')->and($s->inputSchema['required'])->toBe(['describe_la_escena']);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer k-123'));
});

it('accepts an array response and keeps every job id', function () {
    Http::fake(['https://api.krea.test/node-apps/ver-1/execute' => Http::response([['job_id' => 'a', 'status' => 'queued'], ['job_id' => 'b', 'status' => 'queued']])]);
    $out = $this->engine->submit('ver-1', ['describe_la_escena' => 'x']);
    expect($out->isAccepted())->toBeTrue()->and($out->jobIds)->toBe(['a', 'b']);
});

it('accepts a single object response', function () {
    Http::fake(['*/execute' => Http::response(['job_id' => 'solo', 'status' => 'queued'])]);
    expect($this->engine->submit('ver-1', [])->jobIds)->toBe(['solo']);
});

it('rejects on 4xx with the mapped message and detail', function () {
    Http::fake(['*/execute' => Http::response(['message' => 'sin saldo'], 402)]);
    $out = $this->engine->submit('ver-1', []);
    expect($out->isRejected())->toBeTrue()->and($out->error)->toBe('Saldo insuficiente. sin saldo')->and($out->httpStatus)->toBe(402);
});

it('returns unknown on connection failure and never retries the POST', function () {
    $calls = 0;
    Http::fake(['*/execute' => function () use (&$calls) { $calls++; return Http::failedConnection(); }]);
    $out = $this->engine->submit('ver-1', []);
    expect($out->isUnknown())->toBeTrue()->and($calls)->toBe(1);
});

it('wraps a connection failure on describe into KreaException', function () {
    Http::fake(['*/node-apps/*' => Http::failedConnection()]);
    expect(fn () => $this->engine->describe('ver-1'))->toThrow(\App\Engines\KreaException::class);
});

it('treats an empty or job_id-less body as unknown', function () {
    Http::fake(['*/execute' => Http::response([], 200)]);
    expect($this->engine->submit('ver-1', [])->isUnknown())->toBeTrue();
});

it('inspects a job and normalizes status', function (string $native, string $normalized) {
    Http::fake(['https://api.krea.test/jobs/j1' => Http::response(['job_id' => 'j1', 'status' => $native, 'position' => 3, 'result' => ['urls' => ['https://c/x.png']]])]);
    $obs = $this->engine->inspect('j1');
    expect($obs->normalizedStatus)->toBe($normalized)->and($obs->nativeStatus)->toBe($native)->and($obs->queuePosition)->toBe(3);
})->with([['queued', 'pending'], ['backlogged', 'pending'], ['sampling', 'pending'], ['intermediate-complete', 'pending'], ['completed', 'completed'], ['failed', 'failed'], ['cancelled', 'cancelled']]);
```
Run → FAIL.

- [ ] **Step 2: Implement**

`KreaErrorMessages`:
```php
final class KreaErrorMessages
{
    public static function forStatus(?int $status, ?string $detail): string
    {
        $base = match (true) {
            $status === 400 => 'Solicitud inválida.', $status === 401 => 'Clave de acceso inválida o faltante.',
            $status === 402 => 'Saldo insuficiente.', $status === 404 => 'No se encontró el flujo.',
            $status === 429 => 'El servicio está saturado. Intenta en unos minutos.',
            $status !== null && $status >= 500 => 'Error interno del servicio.',
            default => 'Error ' . ($status ?? '') . '.',
        };
        $detail = self::sanitizeDetail($detail);
        return filled($detail) ? $base . ' ' . $detail : $base;
    }
    public static function network(): string { return 'No se pudo conectar con el servicio. Intenta de nuevo en unos segundos.'; }

    public static function sanitizeDetail(?string $detail): ?string
    {
        if ($detail === null) { return null; }
        $d = preg_replace('#data:[a-z]+/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i', '[data-url]', $detail);
        $d = preg_replace('#Bearer\s+\S+#i', 'Bearer [redacted]', $d);
        $d = preg_replace_callback('#[A-Za-z0-9_\-:]{24,}#', fn ($m) => preg_match('/[A-Za-z]/', $m[0]) && preg_match('/\d/', $m[0]) ? '[redacted]' : $m[0], $d);
        return mb_strcut($d, 0, 2048);
    }
}
```
`KreaEngine`:
```php
final class KreaEngine implements ImageEngine
{
    private const PENDING = ['backlogged', 'queued', 'scheduled', 'processing', 'sampling', 'intermediate-complete'];

    public function __construct(private readonly string $apiKey, private readonly string $baseUrl = 'https://api.krea.ai') {}

    private function http(int $timeout): PendingRequest
    { return Http::baseUrl($this->baseUrl)->withToken($this->apiKey)->acceptJson()->connectTimeout(5)->timeout($timeout); }

    public function describe(string $providerRef): EngineSchema
    {
        try { $res = $this->http(15)->retry(2, 500, throw: false)->get("/node-apps/{$providerRef}"); }
        catch (ConnectionException $e) { throw new KreaException(KreaErrorMessages::network(), null); }
        if (! $res->successful()) { throw new KreaException(KreaErrorMessages::forStatus($res->status(), $this->detail($res->json())), $res->status()); }
        $j = $res->json();
        return new EngineSchema((string) ($j['name'] ?? $providerRef), (string) ($j['node_app_version_id'] ?? $providerRef), $j['input_openapi_schema'] ?? null);
    }

    public function submit(string $providerRef, array $inputs): SubmissionOutcome
    {
        try { $res = $this->http(60)->post("/node-apps/{$providerRef}/execute", $inputs); }
        catch (ConnectionException $e) { return SubmissionOutcome::unknown(KreaErrorMessages::network()); }
        if ($res->status() >= 500) { return SubmissionOutcome::unknown(KreaErrorMessages::forStatus($res->status(), $this->detail($res->json()))); }
        if (! $res->successful()) { return SubmissionOutcome::rejected(KreaErrorMessages::forStatus($res->status(), $this->detail($res->json())), $res->status()); }
        $body = $res->json(); $jobs = array_is_list($body ?? []) ? ($body ?? []) : [$body];
        $ids = array_values(array_filter(array_map(fn ($j) => is_array($j) ? ($j['job_id'] ?? null) : null, $jobs)));
        return $ids === [] ? SubmissionOutcome::unknown('La respuesta no incluyó job_id.') : SubmissionOutcome::accepted($ids);
    }

    public function inspect(string $jobId): JobObservation
    {
        try { $res = $this->http(15)->get("/jobs/{$jobId}"); }
        catch (ConnectionException $e) { throw new KreaException(KreaErrorMessages::network(), null); }
        if (! $res->successful()) { throw new KreaException(KreaErrorMessages::forStatus($res->status(), $this->detail($res->json())), $res->status()); }
        $j = $res->json(); $j = array_is_list($j) ? ($j[0] ?? []) : $j;
        $native = (string) ($j['status'] ?? 'queued');
        $norm = in_array($native, self::PENDING, true) ? 'pending' : (in_array($native, ['completed', 'failed', 'cancelled'], true) ? $native : 'pending');
        return new JobObservation($norm, $native, isset($j['position']) ? (int) $j['position'] : null, $j['result'] ?? null, $j['error'] ?? ($j['result']['error'] ?? null));
    }

    public function outputs(array $result): array { return ResultFlattener::flatten($result); }

    private function detail(mixed $json): ?string
    { return is_array($json) ? ($json['message'] ?? $json['error'] ?? $json['detail'] ?? null) : null; }
}
```
A 5xx on submit is treated as `unknown` because Krea may have accepted the work before failing to respond; this is deliberate.

`EngineResolver`:
```php
final class EngineResolver
{
    public function forBrand(Brand $brand): ImageEngine { return $this->forSource($brand, $brand->resolveKreaKeySource()); }

    public function forSource(Brand $brand, string $source): ImageEngine
    {
        if (app()->bound(FakeEngine::class)) { return app(FakeEngine::class); }
        $key = $source === 'brand' ? $brand->krea_api_key : config('media.krea.key');
        if (blank($key)) { throw new KreaException(KreaErrorMessages::forStatus(401, null), 401); }
        return new KreaEngine($key, config('media.krea.base_url'));
    }
}
```
Register `$this->app->singleton(EngineResolver::class)` in `AppServiceProvider`. Add to `tests/Pest.php` the helpers:
```php
function fakeEngine(): \App\Engines\FakeEngine { return app()->instance(\App\Engines\FakeEngine::class, new \App\Engines\FakeEngine()); }
/** Snapshot fixture with every key the workers read. */
function snapshot(array $overrides = []): array {
    return array_replace_recursive(['engine' => 'krea', 'provider_ref' => 'ver-test', 'pipeline_label' => 'Test', 'config_revision' => 1,
        'credential_source' => 'studio', 'kind' => 'series', 'inputs' => [], 'labels' => [], 'bindings' => ['image' => null, 'prompt' => null],
        'schema' => [], 'source_piece' => null], $overrides);
}
```
Every `Generation::factory()` default `execution_snapshot` must be `snapshot()` so polling and download fixtures always carry `credential_source` and `provider_ref`.

- [ ] **Step 3: Run** `./vendor/bin/pest tests/Unit/Engines` → PASS. **Step 4: Commit** — `git commit -m "feat: KreaEngine HTTP adapter, error mapping, engine resolver"`

### Task 7b: Gate B — Krea smoke run and real fixtures (ceiling US$10)

**Files:** Create `app/app/Console/Commands/KreaSmoke.php`, `tests/Fixtures/krea/*-{submit,job,result}.json` (sanitized), `tests/Feature/Engines/KreaFixturesTest.php`, `docs/superpowers/research/2026-09-XX-gate-b-results.md`.

Preconditions: Task 6b done, fresh key in `.env`, and the user's explicit approval of the US$10 ceiling in this session. Stop and ask if any is missing. Never run against the prototype key.

- [ ] **Step 1: `krea:smoke` command** — `php artisan krea:smoke {versionId} {--input=* key=value} {--image=* key=path} {--name=}`: builds inputs (images read from local paths into data URLs), calls `KreaEngine::submit`, polls with `inspect` every 4 s up to 10 min printing native status, downloads outputs through a plain `Http::get` (the guarded downloader arrives in Task 8), prints dimensions, hosts and byte sizes, and writes JSON with `KreaErrorMessages::sanitizeDetail` applied to every string to `tests/Fixtures/krea/{name}-submit.json`, `{name}-job.json`, `{name}-result.json`. It never writes to the database.
- [ ] **Step 2: Run at most 8 submissions**, one at a time, checking Krea's balance page between runs: 1 text generation, 1 Skechers-style three-image execution, 1 Invierno-style one-image-plus-four-texts execution, 2 edits (one on an original, one on an upscale if available by then), 3 upscales (landscape, portrait, square). Stop immediately if a run exceeds a proportional share of the ceiling. Record in the results note: job cardinality per app, output hosts, real output dimensions vs `FourKRule::target`, base64 transport acceptance, and which upscaler input controls size.
- [ ] **Step 3: `KreaFixturesTest`** — for each `{name}-submit.json` / `{name}-job.json` pair, `Http::fake` the execute and jobs endpoints with the fixture bodies and assert `KreaEngine` returns the recorded job IDs and that `outputs()` yields the recorded URL count. This is the contract evidence the spec §12 requires before engine tasks count as done.
- [ ] **Step 4: Feed findings forward** — update the sizing heuristics list in Task 14 (`width`/`height`/`scale` names) and the image-field heuristics in Task 9 to the real property names if they differ. Note the changes in the results file.
- [ ] **Step 5: Commit** — `git commit -m "test: Gate B Krea smoke fixtures and contract tests"`

### Task 8: ImageInspector and ResultDownloader

**Files:** Create `app/app/Services/Media/ImageInspector.php`, `ResultDownloader.php`. Test: `tests/Unit/Media/ImageInspectorTest.php`, `tests/Unit/Media/ResultDownloaderTest.php`. Fixtures: generate tiny PNG/JPEG/WebP files in `tests/Fixtures/images/` with GD in a one-off script (`imagecreatetruecolor(4,3)` → `imagepng`, `imagejpeg`, `imagewebp`) plus `not-an-image.txt`.

**Interfaces — Produces:**
- `ImageInspector::inspect(string $bytes): array{mime:string,width:int,height:int,ext:string}` — throws `App\Services\Media\InvalidImageException` (`'La imagen recibida no es JPEG, PNG ni WebP.'`) for anything else. Reads type and size with `getimagesizefromstring`, then **decodes** with `imagecreatefromstring` (GD) and requires the decoded dimensions to match; `ext` is `jpg|png|webp`.
- `ResultDownloader::download(string $url): array{bytes:string,mime:string,width:int,height:int,ext:string}` — HTTPS only, no redirects (`withOptions(['allow_redirects' => false])`), 60 s timeout, no Authorization header, and **bounded buffering**: the response is requested with `withOptions(['stream' => true])`, a `Content-Length` above `media.max_result_bytes` is rejected before reading, and the body is read from `$res->toPsrResponse()->getBody()` in 1 MiB chunks into a `php://memory` stream, aborting once the cap is exceeded, so nothing spills to local disk. Throws `DownloadException` (Spanish message) for transport/size failures and lets `InvalidImageException` propagate for non-image bodies. Task 16 maps the first to `download_failed` and the second to `invalid_result`.

- [ ] **Step 1: Failing tests**

```php
// ImageInspectorTest
it('reads dimensions and mime for supported types', function (string $file, string $mime, string $ext) {
    $r = (new ImageInspector())->inspect(file_get_contents(base_path("tests/Fixtures/images/$file")));
    expect($r)->toMatchArray(['mime' => $mime, 'width' => 4, 'height' => 3, 'ext' => $ext]);
})->with([['tiny.png', 'image/png', 'png'], ['tiny.jpg', 'image/jpeg', 'jpg'], ['tiny.webp', 'image/webp', 'webp']]);

it('rejects non images and truncated images', function () {
    expect(fn () => (new ImageInspector())->inspect('hello'))->toThrow(InvalidImageException::class);
    $png = file_get_contents(base_path('tests/Fixtures/images/tiny.png'));
    expect(fn () => (new ImageInspector())->inspect(substr($png, 0, 40)))->toThrow(InvalidImageException::class);
});

// ResultDownloaderTest
it('downloads and validates an https image without auth header', function () {
    Http::fake(['https://cdn.test/a.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')), 200, ['Content-Type' => 'image/png'])]);
    $r = (new ResultDownloader(new ImageInspector()))->download('https://cdn.test/a.png');
    expect($r['width'])->toBe(4)->and($r['ext'])->toBe('png');
    Http::assertSent(fn ($req) => ! $req->hasHeader('Authorization'));
});
it('refuses http urls', fn () => expect(fn () => (new ResultDownloader(new ImageInspector()))->download('http://cdn.test/a.png'))->toThrow(DownloadException::class));
it('refuses redirects, oversize bodies (by header and by stream), and non-image bodies', function () {
    Http::fake(['https://cdn.test/r' => Http::response('', 302, ['Location' => 'https://x/y'])]);
    expect(fn () => (new ResultDownloader(new ImageInspector()))->download('https://cdn.test/r'))->toThrow(DownloadException::class);
    config(['media.max_result_bytes' => 10]);
    Http::fake(['https://cdn.test/big.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')), 200, ['Content-Length' => '99'])]);
    expect(fn () => (new ResultDownloader(new ImageInspector()))->download('https://cdn.test/big.png'))->toThrow(DownloadException::class);
    Http::fake(['https://cdn.test/nolen.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')), 200)]);
    expect(fn () => (new ResultDownloader(new ImageInspector()))->download('https://cdn.test/nolen.png'))->toThrow(DownloadException::class);
    config(['media.max_result_bytes' => 64 * 1024 * 1024]);
    Http::fake(['https://cdn.test/text' => Http::response('not an image', 200)]);
    expect(fn () => (new ResultDownloader(new ImageInspector()))->download('https://cdn.test/text'))->toThrow(InvalidImageException::class);
});
```
Run → FAIL.

- [ ] **Step 2: Implement**

```php
final class InvalidImageException extends \RuntimeException {}
final class DownloadException extends \RuntimeException {}

final class ImageInspector
{
    private const MAP = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public function inspect(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;
        if (! $info || ! isset(self::MAP[$mime])) { throw new InvalidImageException('La imagen recibida no es JPEG, PNG ni WebP.'); }
        $img = @imagecreatefromstring($bytes); // full decode: rejects truncated or spoofed files
        if ($img === false || imagesx($img) !== (int) $info[0] || imagesy($img) !== (int) $info[1]) { throw new InvalidImageException('La imagen recibida no es JPEG, PNG ni WebP.'); }
        imagedestroy($img);
        return ['mime' => $mime, 'width' => (int) $info[0], 'height' => (int) $info[1], 'ext' => self::MAP[$mime]];
    }
}

final class ResultDownloader
{
    public function __construct(private readonly ImageInspector $inspector) {}
    public function download(string $url): array
    {
        if (! preg_match('#^https://#i', $url)) { throw new DownloadException('URL de resultado no permitida.'); }
        $cap = (int) config('media.max_result_bytes');
        try {
            $res = Http::withOptions(['allow_redirects' => false, 'stream' => true])->connectTimeout(5)->timeout(60)->get($url);
        } catch (\Throwable $e) { throw new DownloadException('No se pudo descargar el resultado.', 0, $e); }
        if ($res->redirect() || ! $res->successful()) { throw new DownloadException('No se pudo descargar el resultado (HTTP ' . $res->status() . ').'); }
        if ((int) $res->header('Content-Length') > $cap) { throw new DownloadException('El resultado supera el tamaño máximo permitido.'); }
        $body = $res->toPsrResponse()->getBody(); $mem = fopen('php://memory', 'w+'); $total = 0;
        while (! $body->eof()) {
            $chunk = $body->read(1024 * 1024); $total += strlen($chunk);
            if ($total > $cap) { fclose($mem); $body->close(); throw new DownloadException('El resultado supera el tamaño máximo permitido.'); }
            fwrite($mem, $chunk);
        }
        rewind($mem); $bytes = stream_get_contents($mem); fclose($mem);
        return ['bytes' => $bytes] + $this->inspector->inspect($bytes); // InvalidImageException propagates
    }
}
```
- [ ] **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: image inspection and guarded result downloader"`

---

## Phase 3 — Pipeline schema, readiness, form

### Task 9: PipelineReadiness and atomic activation

**Files:** Create `app/app/Services/Pipelines/PipelineReadiness.php`, `PipelineActivation.php`. Test: `tests/Feature/Pipelines/PipelineReadinessTest.php`, `tests/Feature/Pipelines/PipelineActivationTest.php`.

**Interfaces — Produces:**
- `PipelineReadiness::evaluate(Pipeline $p): list<string>` — Spanish error strings, empty when ready. Rules:
  - No `properties` in `input_schema` → `"El flujo no publica un esquema de entradas."`
  - Any non-stale field with `input_type = unknown`, or whose `source_schema` still classifies as unsupported (`SchemaSubset::classify(...)['errors']` non-empty) regardless of the Art Director's `input_type` → `"Campo {name}: tipo no soportado."`
  - Any non-stale field with `needs_configuration = true` → `"Campo {name}: nuevo o modificado; revisa su configuración."`
  - Non-stale required field that is hidden without `has_fixed_value` and without a role binding → `"Campo {name}: es obligatorio y no tiene valor fijo."`
  - Field with `has_fixed_value` whose value fails its schema (type/enum/min/max) → `"Campo {name}: el valor fijo no es válido."`
  - Bound field (role prompt/image) with `has_fixed_value` → `"Campo {name}: un campo vinculado no puede tener valor fijo."`
  - Kind `editor`: exactly one non-stale role `image` **and** exactly one role `prompt`, else `"Un editor necesita exactamente una imagen y un prompt vinculados."`; every other required field must be hidden with fixed value → `"Campo {name}: el editor no muestra campos; configura un valor fijo."`
  - Kind `upscaler`: exactly one role `image`, zero `prompt`, else `"Un upscaler necesita exactamente una imagen vinculada y ningún prompt."`; other required fields as for editor.
  - Kind `generator`: at most one role `prompt` → `"Un generador solo puede tener un prompt."`
- `PipelineReadiness::validateValue(PipelineField $f, mixed $v): bool` — public, reused by the composer for scalar values. Reference arrays (`['__upload' => id]`, `['__piece' => id]`) are **not** passed through it; the composer validates those by authorized existence instead (Task 14).
- `PipelineActivation::activate(Pipeline $p): void` — in `DB::transaction`, `Campaign::lockForUpdate()->find(...)`, re-evaluates readiness (throws `ValidationException` with the errors if non-empty), then for editor/upscaler checks no *other* active pipeline of the same kind in the campaign (throws `ValidationException` `"Ya hay un {kind} activo en esta campaña."`), sets `is_active = true`. `deactivate(Pipeline $p)`: sets false and clears `campaigns.default_pipeline_id` if it pointed here. `setDefault(Campaign $c, Pipeline $p)`: validates `p->campaign_id === c->id && kind generator && is_active` else `ValidationException` `"El generador por defecto debe ser un generador activo de esta campaña."`

- [ ] **Step 1: Failing tests** (excerpt; write all listed rules as separate `it()` cases)

```php
it('requires one image and one prompt binding for editors', function () {
    $p = Pipeline::factory()->create(['kind' => PipelineKind::Editor, 'input_schema' => ['properties' => ['a' => ['type' => 'string']]]]);
    PipelineField::factory()->for($p)->create(['name' => 'foto', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    expect(app(PipelineReadiness::class)->evaluate($p->refresh()))->toContain('Un editor necesita exactamente una imagen y un prompt vinculados.');
    PipelineField::factory()->for($p)->create(['name' => 'quiero_editar', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    expect(app(PipelineReadiness::class)->evaluate($p->refresh()))->toBe([]);
});

it('flags hidden required fields without a fixed value and validates typed fixed values', function () {
    $p = Pipeline::factory()->create(['input_schema' => ['properties' => ['n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]]]]);
    $f = PipelineField::factory()->for($p)->create(['name' => 'n', 'input_type' => InputType::Integer, 'required' => true, 'visibility' => FieldVisibility::Hidden, 'source_schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]]);
    expect(app(PipelineReadiness::class)->evaluate($p->refresh()))->toContain('Campo n: es obligatorio y no tiene valor fijo.');
    $f->update(['has_fixed_value' => true, 'fixed_value' => 9]);
    expect(app(PipelineReadiness::class)->evaluate($p->refresh()))->toContain('Campo n: el valor fijo no es válido.');
    $f->update(['fixed_value' => 0]);   // zero is a real value, but out of range
    expect(app(PipelineReadiness::class)->evaluate($p->refresh()))->toContain('Campo n: el valor fijo no es válido.');
    $f->update(['fixed_value' => 2]);
    expect(app(PipelineReadiness::class)->evaluate($p->refresh()))->toBe([]);
});

it('allows only one active editor per campaign and takes a row lock while activating', function () {
    $sqls = []; DB::listen(fn ($q) => $sqls[] = $q->sql);
    $campaign = Campaign::factory()->create();
    [$a, $b] = Pipeline::factory()->count(2)->for($campaign)->create(['kind' => PipelineKind::Editor, 'input_schema' => ['properties' => []]])->all();
    foreach ([$a, $b] as $p) { PipelineField::factory()->for($p)->create(['name' => 'img', 'input_type' => InputType::Image, 'role' => FieldRole::Image]); PipelineField::factory()->for($p)->create(['name' => 'txt', 'input_type' => InputType::String, 'role' => FieldRole::Prompt]); }
    app(PipelineActivation::class)->activate($a);
    expect(fn () => app(PipelineActivation::class)->activate($b))->toThrow(ValidationException::class);
    expect($campaign->pipelines()->where('is_active', true)->count())->toBe(1)
        ->and(collect($sqls)->contains(fn ($q) => str_contains(strtolower($q), 'for update')))->toBeTrue();
});

it('rejects a default pipeline from another campaign or of the wrong kind', function () {
    $c = Campaign::factory()->create(); $other = Pipeline::factory()->create(['kind' => PipelineKind::Generator, 'is_active' => true]);
    $editor = Pipeline::factory()->for($c)->create(['kind' => PipelineKind::Editor, 'is_active' => true]);
    expect(fn () => app(PipelineActivation::class)->setDefault($c, $other))->toThrow(ValidationException::class)
        ->and(fn () => app(PipelineActivation::class)->setDefault($c, $editor))->toThrow(ValidationException::class);
});
```
Run → FAIL.

- [ ] **Step 2: Implement** `PipelineReadiness` following the rule list verbatim; `validateValue` checks: integer → `is_int`, number → `is_int || is_float`, boolean → `is_bool`, string/image → `is_string`, nullable schemas accept `null`, `enum` membership with strict comparison, `minimum/maximum`, `minLength/maxLength`, and string `pattern` via `preg_match('#' . str_replace('#', '\\#', $pattern) . '#u', $v)`. Implement `PipelineActivation` as specified with `DB::transaction` and `lockForUpdate()` on the campaign row. `SchemaSubset` (Task 10) is a pure static class with no dependencies; create it in this task so readiness can call `classify`, and move its tests here. The "concurrent" activation test is sequential by necessity in PHPUnit; complement it with a test that asserts the campaign row is locked (`DB::listen` sees `... FOR UPDATE`) and rely on the real-MySQL walkthrough for the race itself.

- [ ] **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: pipeline readiness rules and atomic activation"`

### Task 10: SchemaSubset and PipelineSchemaSync

**Files:** Create `app/app/Services/Pipelines/SchemaSubset.php`, `PipelineSchemaSync.php`. Test: `tests/Unit/Pipelines/SchemaSubsetTest.php`, `tests/Feature/Pipelines/PipelineSchemaSyncTest.php`.

**Interfaces — Produces:**
- `SchemaSubset::classify(array $schema, string $name): array{input_type: InputType, errors: list<string>}` — decides the default `InputType` for one property schema and lists unsupported keywords. Supported keywords are exactly `type|enum|default|minLength|maxLength|pattern|minimum|maximum|nullable|description|title|format|examples`; **any other keyword** yields `"Palabra clave no soportada: <keyword>"`. `type` must be one of `string|integer|number|boolean` (optionally as `['string','null']`); `array|object`, unions, and missing type yield `"Tipo no soportado: <type>"`. Errors are deduplicated but a schema may legitimately produce several. `format: uri|binary` or a name/description containing `imagen|image|foto|photo` → returns `InputType::Image` as *suggestion*.
- `PipelineSchemaSync::sync(Pipeline $pipeline): Pipeline` — fetches via `EngineResolver->forBrand($pipeline->campaign->brand)->describe($pipeline->provider_ref)` **outside** the transaction, then inside `DB::transaction`: re-loads the pipeline **with** `lockForUpdate()` and works on that fresh instance (so `config_revision + 1` cannot be lost), updates `input_schema`, `schema_fetched_at`, increments `config_revision`, upserts fields (preserving `label_override`, `help_text`, `visibility`, `has_fixed_value`, `fixed_value`, `role`; keeping an existing `input_type` when compatible, otherwise replacing it **and** setting `needs_configuration = true`), marks **new required** fields on an existing pipeline `needs_configuration = true`, marks missing fields `stale = true`, un-stales fields that reappear, then calls `PipelineReadiness::evaluate` and stores errors. If the pipeline was active and now has errors, it calls `PipelineActivation::deactivate` (which also clears an invalid campaign default). Throws `KreaException` on fetch failure without touching the row. First sync proposes role `prompt` on the **first non-image string by `sort_order`** (spec §4.2).

- [ ] **Step 1: Failing tests**

`tests/Unit/Pipelines/SchemaSubsetTest.php`:
```php
<?php

use App\Enums\InputType;
use App\Services\Pipelines\SchemaSubset;

it('classifies primitives and suggests image from format or wording', function (array $schema, string $name, InputType $type, int $errors) {
    $r = SchemaSubset::classify($schema, $name);
    expect($r['input_type'])->toBe($type)->and($r['errors'])->toHaveCount($errors);
})->with([
    [['type' => 'string'], 'describe_la_escena', InputType::String, 0],
    [['type' => ['string', 'null'], 'maxLength' => 200], 'nota', InputType::String, 0],
    [['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'cantidad', InputType::Integer, 0],
    [['type' => 'number'], 'escala', InputType::Number, 0],
    [['type' => 'boolean', 'default' => false], 'hd', InputType::Boolean, 0],
    [['type' => 'string', 'enum' => ['1:1', '16:9']], 'aspect', InputType::String, 0],
    [['type' => 'string', 'format' => 'uri'], 'foto_para_editar', InputType::Image, 0],
    [['type' => 'string', 'description' => 'Imagen de referencia'], 'imagen_de_refe', InputType::Image, 0],
    [['type' => 'array', 'items' => ['type' => 'string']], 'tags', InputType::Unknown, 2],
    [['$ref' => '#/x'], 'ref', InputType::Unknown, 2],
    [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], 'u', InputType::Unknown, 2],
    [['type' => 'string', 'x-custom' => 1], 'weird', InputType::Unknown, 1],
]);
```
`tests/Feature/Pipelines/PipelineSchemaSyncTest.php`:
```php
<?php

use App\Enums\{FieldRole, FieldVisibility, InputType};
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineSchemaSync;

function skechersSchema(): array {
    return ['type' => 'object', 'required' => ['imagen_de_refe', 'modelo', 'tenis'], 'properties' => [
        'imagen_de_refe' => ['type' => 'string', 'format' => 'uri'], 'modelo' => ['type' => 'string', 'description' => 'Foto del modelo'], 'tenis' => ['type' => 'string', 'format' => 'uri']]];
}
function creadorSchema(): array {
    return ['type' => 'object', 'required' => ['describe_la_escena'], 'properties' => ['describe_la_escena' => ['type' => 'string'], 'estilo' => ['type' => 'string', 'enum' => ['a', 'b']]]];
}

it('creates fields from the schema, proposes roles and bumps revision', function () {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'ver-creador']);
    fakeEngine()->withSchema('ver-creador', creadorSchema(), 'Creador');
    app(PipelineSchemaSync::class)->sync($pipeline);
    $pipeline->refresh();
    expect($pipeline->config_revision)->toBe(2)->and($pipeline->fields)->toHaveCount(2)
        ->and($pipeline->fields->firstWhere('name', 'describe_la_escena')->role)->toBe(FieldRole::Prompt)
        ->and($pipeline->fields->firstWhere('name', 'estilo')->input_type)->toBe(InputType::String)
        ->and($pipeline->schema_fetched_at)->not->toBeNull();
});

it('flags new required fields and type changes for configuration and deactivates an invalid active pipeline', function () {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'v', 'is_active' => true]);
    $engine = fakeEngine()->withSchema('v', creadorSchema());
    $sync = app(PipelineSchemaSync::class); $sync->sync($pipeline); $pipeline->refresh();
    $pipeline->update(['is_active' => true, 'readiness_errors' => []]);
    $engine->withSchema('v', ['type' => 'object', 'required' => ['describe_la_escena', 'marca'], 'properties' => ['describe_la_escena' => ['type' => 'integer'], 'marca' => ['type' => 'string']]]);
    $sync->sync($pipeline->refresh()); $pipeline->refresh();
    expect($pipeline->fields()->where('name', 'marca')->value('needs_configuration'))->toBeTruthy()
        ->and($pipeline->fields()->where('name', 'describe_la_escena')->first())->needs_configuration->toBeTruthy()->input_type->toBe(InputType::Integer)
        ->and($pipeline->is_active)->toBeFalse()
        ->and(collect($pipeline->readiness_errors)->contains(fn ($e) => str_contains($e, 'revisa su configuración')))->toBeTrue();
});

it('marks removed fields stale, keeps overrides, and does not block on removed required', function () {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'v']);
    $engine = fakeEngine()->withSchema('v', skechersSchema());
    $sync = app(PipelineSchemaSync::class); $sync->sync($pipeline);
    $pipeline->fields()->where('name', 'tenis')->update(['label_override' => 'Zapatilla', 'visibility' => FieldVisibility::Hidden->value]);
    $engine->withSchema('v', ['type' => 'object', 'required' => ['modelo'], 'properties' => ['modelo' => ['type' => 'string', 'format' => 'uri'], 'nuevo' => ['type' => 'string']]]);
    $sync->sync($pipeline->refresh());
    $pipeline->refresh();
    expect($pipeline->fields()->where('name', 'imagen_de_refe')->value('stale'))->toBeTruthy()
        ->and($pipeline->fields()->where('name', 'tenis')->first())->stale->toBeTruthy()->label_override->toBe('Zapatilla')
        ->and($pipeline->fields()->where('name', 'nuevo')->exists())->toBeTrue()
        ->and(collect($pipeline->readiness_errors)->contains(fn ($e) => str_contains($e, 'imagen_de_refe')))->toBeFalse();
});

it('leaves the pipeline untouched when the fetch fails', function () {
    $pipeline = Pipeline::factory()->create(['provider_ref' => 'missing', 'config_revision' => 1]);
    fakeEngine();
    expect(fn () => app(PipelineSchemaSync::class)->sync($pipeline))->toThrow(\App\Engines\KreaException::class);
    expect($pipeline->fresh()->config_revision)->toBe(1)->and($pipeline->fresh()->input_schema)->toBeNull();
});
```
Run → FAIL.

- [ ] **Step 2: Implement `SchemaSubset`**

```php
final class SchemaSubset
{
    private const SUPPORTED_KEYS = ['type','enum','default','minLength','maxLength','pattern','minimum','maximum','nullable','description','title','format','examples'];

    /** @return array{input_type: InputType, errors: list<string>} */
    public static function classify(array $schema, string $name): array
    {
        $errors = [];
        foreach (array_keys($schema) as $k) { if (! in_array($k, self::SUPPORTED_KEYS, true)) { $errors[] = "Palabra clave no soportada: {$k}"; } }
        $types = (array) ($schema['type'] ?? []); $types = array_values(array_diff($types, ['null']));
        $type = $types[0] ?? null;
        if (count($types) > 1) { $errors[] = 'Tipo no soportado: union'; }
        if (in_array($type, ['array', 'object'], true)) { $errors[] = "Tipo no soportado: {$type}"; }
        if ($errors) { return ['input_type' => InputType::Unknown, 'errors' => array_values(array_unique($errors))]; }
        if (isset($schema['enum']) && array_filter($schema['enum'], fn ($v) => ! is_scalar($v) && $v !== null)) { return ['input_type' => InputType::Unknown, 'errors' => ['Tipo no soportado: enum no escalar']]; }
        $looksImage = in_array($schema['format'] ?? null, ['uri', 'binary'], true)
            || preg_match('/imagen|image|foto|photo/i', $name . ' ' . ($schema['description'] ?? '') . ' ' . ($schema['title'] ?? ''));
        return ['input_type' => match ($type) {
            'string' => $looksImage ? InputType::Image : InputType::String,
            'integer' => InputType::Integer, 'number' => InputType::Number, 'boolean' => InputType::Boolean,
            default => InputType::Unknown,
        }, 'errors' => $type === null || ! in_array($type, ['string','integer','number','boolean'], true) ? ['Tipo no soportado: ' . ($type ?? 'desconocido')] : []];
    }

    public static function isCompatible(InputType $existing, InputType $fresh): bool
    { return $existing === $fresh || ($existing === InputType::Image && $fresh === InputType::String) || ($existing === InputType::String && $fresh === InputType::Image); }
}
```

- [ ] **Step 3: Implement `PipelineSchemaSync`**

```php
final class PipelineSchemaSync
{
    public function __construct(private readonly EngineResolver $engines, private readonly PipelineReadiness $readiness, private readonly PipelineActivation $activation) {}

    public function sync(Pipeline $pipeline): Pipeline
    {
        $schema = $this->engines->forBrand($pipeline->campaign->brand)->describe($pipeline->provider_ref); // may throw KreaException
        $props = $schema->inputSchema['properties'] ?? null; $required = $schema->inputSchema['required'] ?? [];
        $locked = DB::transaction(function () use ($pipeline, $schema, $props, $required) {
            $p = Pipeline::query()->lockForUpdate()->findOrFail($pipeline->id); // fresh instance under lock; never reuse the caller's copy
            $wasActive = $p->is_active;
            $p->forceFill(['input_schema' => $schema->inputSchema, 'schema_fetched_at' => now(), 'config_revision' => $p->config_revision + 1])->save();
            $existing = $p->fields()->get()->keyBy('name'); $seen = []; $order = 0; $firstSync = $existing->isEmpty();
            foreach (($props ?? []) as $name => $prop) {
                $c = SchemaSubset::classify((array) $prop, (string) $name); $seen[] = $name; $isRequired = in_array($name, $required, true);
                $field = $existing->get($name);
                if (! $field) {
                    $field = new PipelineField(['pipeline_id' => $p->id, 'name' => $name, 'input_type' => $c['input_type'], 'role' => $c['input_type'] === InputType::Image ? FieldRole::Image : FieldRole::None, 'visibility' => FieldVisibility::Visible,
                        'needs_configuration' => ! $firstSync && $isRequired]); // a new required field on a live pipeline needs a human look
                } elseif (! SchemaSubset::isCompatible($field->input_type, $c['input_type'])) {
                    $field->input_type = $c['input_type']; $field->needs_configuration = true;
                }
                $field->forceFill(['source_schema' => $prop, 'required' => $isRequired, 'stale' => false, 'sort_order' => $order++])->save();
            }
            $p->fields()->whereNotIn('name', $seen)->update(['stale' => true]);
            if ($firstSync) {
                $p->fields()->where('stale', false)->where('input_type', InputType::String->value)->orderBy('sort_order')->first()?->update(['role' => FieldRole::Prompt]);
            }
            $errors = $this->readiness->evaluate($p->refresh());
            $p->forceFill(['readiness_errors' => $errors])->save();
            if ($wasActive && $errors !== []) { $this->activation->deactivate($p); }
            return $p;
        });
        return $locked->refresh();
    }
}
```
If `$props === null` the pipeline is saved with `readiness_errors` containing `"El flujo no publica un esquema de entradas."` (added by `PipelineReadiness`, Task 10, when `input_schema` lacks `properties`).

- [ ] **Step 4: Run** both tests → PASS (`PipelineReadiness`, `PipelineActivation`, and `SchemaSubset` exist from Task 9). **Step 5: Commit** — `git commit -m "feat: pipeline schema sync with configuration flags and auto-deactivation"`

### Task 11: PipelineFormBuilder

**Files:** Create `app/app/Services/Pipelines/PipelineFormBuilder.php`. Test: `tests/Unit/Pipelines/PipelineFormBuilderTest.php`.

**Interfaces — Produces:**
- `PipelineFormBuilder::components(Pipeline $p): list<\Filament\Schemas\Components\Component>` — one component per non-stale visible field, statePath `inputs.{name}`, ordered: the `prompt` role first, then by `sort_order`.
- `PipelineFormBuilder::rules(Pipeline $p): array<string, list<string|Rule>>` — Laravel validation rules per visible field: `required` whenever `required` is true (spec §4.3; nullability only matters for fixed values), else `nullable`; type rules `integer|numeric|boolean|string` **except** for enum fields, which get only `in:<json-encoded keys>` because Laravel's `boolean` rule rejects `"true"`/`"false"` keys; `min/max` for numerics, `min/max` string lengths, and `regex:` for `pattern`. Image fields are validated by Filament's `FileUpload` itself.
- `PipelineFormBuilder::label(PipelineField $f): string` = `label_override ?? Str::headline(str_replace('_',' ', name))`, and for role prompt with no override: `"Qué quieres ver"`.
- Component mapping exactly as spec §4.3: prompt → `Textarea::make()->rows(4)`, image → `FileUpload::make()->image()->disk('inputs')->directory('tmp')->visibility('private')->maxSize(config('media.max_upload_kb'))->preventFilePathTampering()->allowFilePathUsing(fn (string $path) => str_starts_with($path, 'tmp/') || InputUpload::query()->where('storage_path', $path)->where('user_id', auth()->id())->exists())` — temporary Livewire paths are always allowed, and a finalized path is allowed only when the current user owns it (this is what lets the Generator keep showing the previous submission's image after finalization), string → `Textarea::make()->rows(2)`, enum → `Select::make()->options(...)` with keys = JSON-encoded values so types round-trip (decode in composer), integer/number → `TextInput::make()->numeric()` (+ `->integer()` for integer), boolean → `Toggle::make()`, unknown → throws `LogicException('Pipeline not ready')`. Read-only messages inside schemas use `Filament\Infolists\Components\TextEntry`, not the deprecated `Placeholder`.

- [ ] **Step 1: Failing test**

```php
it('renders each supported type, orders prompt first, and labels correctly', function () {
    $p = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($p)->create(['name' => 'hd', 'input_type' => InputType::Boolean, 'sort_order' => 0]);
    PipelineField::factory()->for($p)->create(['name' => 'imagen', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'sort_order' => 1]);
    PipelineField::factory()->for($p)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true, 'sort_order' => 2]);
    PipelineField::factory()->for($p)->create(['name' => 'aspect', 'input_type' => InputType::String, 'source_schema' => ['type' => 'string', 'enum' => ['1:1', '16:9']], 'sort_order' => 3]);
    PipelineField::factory()->for($p)->create(['name' => 'n', 'input_type' => InputType::Integer, 'sort_order' => 4, 'source_schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]]);
    PipelineField::factory()->for($p)->create(['name' => 'oculto', 'input_type' => InputType::String, 'visibility' => FieldVisibility::Hidden, 'sort_order' => 5]);
    PipelineField::factory()->for($p)->create(['name' => 'viejo', 'input_type' => InputType::String, 'stale' => true, 'sort_order' => 6]);

    $components = app(PipelineFormBuilder::class)->components($p->refresh());
    $names = array_map(fn ($c) => $c->getName(), $components);
    expect($names)->toBe(['inputs.describe_la_escena', 'inputs.hd', 'inputs.imagen', 'inputs.aspect', 'inputs.n'])
        ->and($components[0])->toBeInstanceOf(Textarea::class)->and($components[0]->getLabel())->toBe('Qué quieres ver')
        ->and($components[1])->toBeInstanceOf(Toggle::class)->and($components[2])->toBeInstanceOf(FileUpload::class)
        ->and($components[3])->toBeInstanceOf(Select::class)->and($components[4])->toBeInstanceOf(TextInput::class);
    $rules = app(PipelineFormBuilder::class)->rules($p);
    expect($rules['inputs.describe_la_escena'])->toContain('required')->and($rules['inputs.n'])->toContain('integer', 'min:1', 'max:4')
        ->and($rules['inputs.aspect'])->toContain('in:"1:1","16:9"')->and(collect($rules['inputs.aspect'])->contains('string'))->toBeFalse();
});
```
Component getters (`getName()`, `getLabel()`) may require a container in Filament; if so, wrap components in `Schema::make()->components([...])` inside the test before asserting. Run → FAIL.

- [ ] **Step 2: Implement** as described in Interfaces. Enum option keys: `json_encode($value)` → label `(string) $value`.

- [ ] **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: schema-driven Filament form builder"`

---

## Phase 4 — Media: signed URLs and uploads

### Task 12: SignedUrlProvider with CloudFront and presigned implementations

**Files:** Create `app/app/Services/Media/SignedUrlProvider.php`, `CloudFrontSignedUrlProvider.php`, `PresignedS3UrlProvider.php`; bind in `AppServiceProvider` by `config('media.url_provider')`. Test: `tests/Unit/Media/SignedUrlProviderTest.php`.

**Interfaces — Produces:**
```php
interface SignedUrlProvider {
    public function url(string $disk, string $path, \DateTimeInterface $expiresAt, bool $download = false, ?string $filename = null): string;
}
```
- `PresignedS3UrlProvider`: `Storage::disk($disk)->temporaryUrl($path, $expiresAt, $download ? ['ResponseContentDisposition' => 'attachment; filename="'.$filename.'"'] : [])`.
- `CloudFrontSignedUrlProvider`: builds `https://{domain}/{diskRoot}/{path}` where `diskRoot` is `config("filesystems.disks.$disk.root")`, appends `?response-content-disposition=attachment%3B%20filename%3D...` when `$download`, and signs with `Aws\CloudFront\UrlSigner($keyPairId, $privateKeyPath)->getSignedUrl($url, $expiresAt->getTimestamp())`. Requires `composer require aws/aws-sdk-php` (already present through flysystem-aws-s3-v3).
- Helper `Media::ttl(): \DateTimeInterface` = `now()->addSeconds(config('media.signed_url_ttl'))` in `app/Support/Media.php`.

- [ ] **Step 1: Failing test**

```php
it('produces a cloudfront signed url with the expected query parameters', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048]); openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'cf'); file_put_contents($path, $pem);
    config(['media.cloudfront' => ['domain' => 'd111.cloudfront.net', 'key_pair_id' => 'KPID', 'private_key_path' => $path], 'filesystems.disks.pieces.root' => 'pieces']);
    $url = (new CloudFrontSignedUrlProvider())->url('pieces', 'b/c/1.png', now()->addMinutes(10), true, 'pieza.png');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect(parse_url($url, PHP_URL_HOST))->toBe('d111.cloudfront.net')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/pieces/b/c/1.png')
        ->and($q)->toHaveKeys(['Expires', 'Signature', 'Key-Pair-Id', 'response-content-disposition'])
        ->and($q['Key-Pair-Id'])->toBe('KPID');
});

it('resolves the provider from config', function () {
    config(['media.url_provider' => 'presigned']);
    expect(app(SignedUrlProvider::class))->toBeInstanceOf(PresignedS3UrlProvider::class);
});
```
Run → FAIL.

- [ ] **Step 2: Implement** both providers and the binding:
```php
$this->app->bind(SignedUrlProvider::class, fn () => config('media.url_provider') === 'cloudfront' ? new CloudFrontSignedUrlProvider() : new PresignedS3UrlProvider());
```
- [ ] **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: signed URL providers for CloudFront and presigned S3"`

### Task 13: InputUploadService (finalize temporary uploads into owned records)

**Files:** Create `app/app/Services/Media/InputUploadService.php`. Test: `tests/Feature/Media/InputUploadServiceTest.php`.

**Interfaces — Produces:**
- `InputUploadService::finalize(string $temporaryPath, Brand $brand, User $user): InputUpload` — reads the temp object from disk `inputs` (path relative to disk root, e.g. `tmp/livewire-…png`), inspects it (`ImageInspector`), rejects >20 MiB or unsupported types with `ValidationException` (`"La imagen debe ser JPEG, PNG o WebP de hasta 20 MB."`), copies to `{brand_id}/{uuid}.{ext}` on the `inputs` disk, deletes the temp object, and creates `InputUpload` with `finalized_at = now()`. Path traversal (`..`) or a path not under `tmp/` → `ValidationException` `"Ruta de archivo no válida."`.
- `InputUploadService::authorize(int $uploadId, Brand $brand, User $user): InputUpload` — `firstOrFail` where `brand_id = brand->id` **and** `user_id = user->id`; throws `AuthorizationException` otherwise. Another Editor's upload in the same brand is rejected (spec §9).
- `InputUploadService::authorizeForPipeline(int $uploadId, Pipeline $pipeline): InputUpload` — the upload must be linked to the pipeline through `pipeline_inputs` (Art Director fixed images, Task 19); throws `AuthorizationException` otherwise.
- `InputUploadService::bytes(InputUpload $u): string` — `Storage::disk('inputs')->get($u->storage_path)`.
- `InputUploadService::dataUrl(InputUpload $u): string` — `"data:{$mime};base64,".base64_encode(bytes)`.

- [ ] **Step 1: Failing test**

```php
it('finalizes a temporary upload into an owned immutable object', function () {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('tmp/x.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $brand = Brand::factory()->create(); $user = User::factory()->editor()->create();
    $u = app(InputUploadService::class)->finalize('tmp/x.png', $brand, $user);
    expect($u->brand_id)->toBe($brand->id)->and($u->width)->toBe(4)->and($u->mime_type)->toBe('image/png')
        ->and(Storage::disk('inputs')->exists($u->storage_path))->toBeTrue()->and(Storage::disk('inputs')->exists('tmp/x.png'))->toBeFalse()
        ->and(str_starts_with($u->storage_path, "{$brand->id}/"))->toBeTrue();
});

it('rejects traversal, non-tmp paths, and another brand on authorize', function () {
    Storage::fake('inputs'); $brand = Brand::factory()->create(); $user = User::factory()->editor()->create();
    expect(fn () => app(InputUploadService::class)->finalize('../secret.png', $brand, $user))->toThrow(ValidationException::class)
        ->and(fn () => app(InputUploadService::class)->finalize('5/other.png', $brand, $user))->toThrow(ValidationException::class);
    $foreign = InputUpload::factory()->create();
    expect(fn () => app(InputUploadService::class)->authorize($foreign->id, $brand, $user))->toThrow(AuthorizationException::class);
    $colleague = User::factory()->editor()->create(); $brand->users()->attach($colleague);
    $theirs = InputUpload::factory()->create(['brand_id' => $brand->id, 'user_id' => $colleague->id]);
    expect(fn () => app(InputUploadService::class)->authorize($theirs->id, $brand, $user))->toThrow(AuthorizationException::class);
});
```
Run → FAIL. **Step 2: Implement** as specified. **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: input upload finalization with ownership records"`

---

## Phase 5 — Generation chain

### Task 14: FourKRule and InputComposer

**Files:** Create `app/app/Services/Generation/FourKRule.php`, `InputComposer.php`. Test: `tests/Unit/Generation/FourKRuleTest.php`, `tests/Feature/Generation/InputComposerTest.php`.

**Interfaces — Produces:**
- `FourKRule::target(int $w, int $h): array{width:int,height:int}` — `s = 3840 / max(w,h)`, `[round(w*s), round(h*s)]`.
- `FourKRule::accepts(int $srcW, int $srcH, int $outW, int $outH): bool` — output equals `target(src)`.
- `FourKRule::meetsTarget(int $w, int $h): bool` — `max(w,h) >= 3840`.
- `InputComposer::compose(Pipeline $p, array $visibleInputs, ?Piece $sourcePiece = null, ?string $instruction = null): array{inputs: array, uploadIds: list<int>}` — server-side composition of the provider payload from **field configuration**, never trusting extra client keys:
  - **extra keys:** any key in `visibleInputs` that is not a non-stale visible field → `ValidationException("Campos no permitidos: a, b")` before anything else.
  - visible fields: take `visibleInputs[name]`; enum values decoded from JSON keys; integers cast; booleans cast; images: value is an `InputUpload` id (already finalized and **authorized for this user** by the caller via `InputUploadService::authorize`) → stored as `['__upload' => id]` reference (expanded to data URL later in the worker); omit absent optional fields; throw `ValidationException` for missing required.
  - hidden fields with `has_fixed_value`: include `fixed_value` (image fixed values are `['__upload' => id]` too, via `pipeline_inputs`).
  - role `image` on editor/upscaler: `['__piece' => sourcePiece->id]`; role `prompt` on editor: `$instruction`.
  - upscaler size: if the pipeline has non-stale fields named `width` and `height` (or `target_width`/`target_height`) of type integer, fill them from `FourKRule::target($sourcePiece->width, $sourcePiece->height)`; if a single field named `scale`/`scale_factor` of type number exists, set `3840 / max(w,h)` rounded to 2 decimals. Otherwise leave sizing to the Art Director's fixed values.
  - Validates every **scalar** value with `PipelineReadiness::validateValue`. Reference arrays are validated by existence: `__upload` must resolve through `InputUploadService::authorize` (visible) or `authorizeForPipeline` (fixed), `__piece` must be the supplied `$sourcePiece`.

- [ ] **Step 1: Failing tests**

```php
// FourKRuleTest
it('computes exact 4K targets', function (int $w, int $h, int $tw, int $th) {
    expect(FourKRule::target($w, $h))->toBe(['width' => $tw, 'height' => $th]);
})->with([[1920, 1080, 3840, 2160], [1080, 1920, 2160, 3840], [1024, 1024, 3840, 3840], [1000, 667, 3840, 2561]]);
it('accepts only exact matches', fn () => expect(FourKRule::accepts(1920, 1080, 3840, 2160))->toBeTrue()->and(FourKRule::accepts(1920, 1080, 3840, 2158))->toBeFalse()->and(FourKRule::accepts(1024, 1024, 3840, 2160))->toBeFalse());

// InputComposerTest
it('rejects extra client keys before composing', function () {
    $p = Pipeline::factory()->create(['kind' => PipelineKind::Generator, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($p)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    expect(fn () => app(InputComposer::class)->compose($p->refresh(), ['describe_la_escena' => 'x', 'evil' => 'y']))
        ->toThrow(ValidationException::class, 'Campos no permitidos: evil');
});

it('composes visible, fixed, and bound inputs', function () {
    $p = Pipeline::factory()->create(['kind' => PipelineKind::Editor, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($p)->create(['name' => 'foto_para_editar', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($p)->create(['name' => 'quiero_editar', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    PipelineField::factory()->for($p)->create(['name' => 'fuerza', 'input_type' => InputType::Number, 'visibility' => FieldVisibility::Hidden, 'has_fixed_value' => true, 'fixed_value' => 0.5, 'source_schema' => ['type' => 'number']]);
    $piece = Piece::factory()->create();
    $r = app(InputComposer::class)->compose($p->refresh(), [], $piece, 'quita la caja');
    expect($r['inputs'])->toBe(['foto_para_editar' => ['__piece' => $piece->id], 'quiero_editar' => 'quita la caja', 'fuerza' => 0.5]);
});

it('fills upscaler width and height from the 4K rule', function () {
    $p = Pipeline::factory()->create(['kind' => PipelineKind::Upscaler, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($p)->create(['name' => 'image', 'input_type' => InputType::Image, 'role' => FieldRole::Image, 'required' => true]);
    PipelineField::factory()->for($p)->create(['name' => 'width', 'input_type' => InputType::Integer, 'visibility' => FieldVisibility::Hidden, 'source_schema' => ['type' => 'integer']]);
    PipelineField::factory()->for($p)->create(['name' => 'height', 'input_type' => InputType::Integer, 'visibility' => FieldVisibility::Hidden, 'source_schema' => ['type' => 'integer']]);
    $piece = Piece::factory()->create(['width' => 1920, 'height' => 1080]);
    expect(app(InputComposer::class)->compose($p->refresh(), [], $piece)['inputs'])->toMatchArray(['width' => 3840, 'height' => 2160]);
});

it('requires visible required fields and decodes enum json keys', function () {
    $p = Pipeline::factory()->create(['kind' => PipelineKind::Generator, 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($p)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    PipelineField::factory()->for($p)->create(['name' => 'n', 'input_type' => InputType::Integer, 'source_schema' => ['type' => 'integer', 'enum' => [1, 2]]]);
    expect(fn () => app(InputComposer::class)->compose($p->refresh(), []))->toThrow(ValidationException::class);
    expect(app(InputComposer::class)->compose($p, ['describe_la_escena' => 'x', 'n' => '2'])['inputs'])->toBe(['describe_la_escena' => 'x', 'n' => 2]);
});
```
Run → FAIL. **Step 2: Implement** as specified. **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: 4K rule and server-side input composer"`

### Task 15: CreateGeneration and RunGenerationJob (submission claim)

**Files:** Create `app/app/Services/Generation/CreateGeneration.php`, `app/app/Jobs/RunGenerationJob.php`, `app/app/Services/Generation/SnapshotExpander.php`. Test: `tests/Feature/Generation/CreateGenerationTest.php`, `tests/Feature/Generation/RunGenerationJobTest.php`.

**Interfaces — Produces:**
- `CreateGeneration::series(User $user, Campaign $campaign, Pipeline $pipeline, array $visibleInputs, string $requestId, int $formRevision): Generation`
- `CreateGeneration::edit(User $user, Piece $source, string $instruction, string $requestId): Generation`
- `CreateGeneration::upscale(User $user, Piece $source, string $requestId): Generation`
  - All: authorize `user->brands` contains `campaign->brand_id` (`AuthorizationException`), campaign not soft-deleted (`ValidationException("Esta campaña ya no está disponible.")`), pipeline belongs to campaign and `isReady()` (`ValidationException` `"Esta campaña no tiene {generador|editor|upscaler} configurado."`), for series `formRevision === pipeline->config_revision` else `ValidationException("La configuración cambió. Recarga el formulario.")`, source piece belongs to campaign. Idempotent: if a Generation with `request_id` exists for this user, return it. Snapshot = `['engine' => 'krea', 'provider_ref', 'pipeline_label', 'config_revision', 'credential_source' => brand->resolveKreaKeySource(), 'kind', 'inputs' => composed, 'labels' => [name => label], 'bindings' => ['image' => name|null, 'prompt' => name|null], 'schema' => [name => ['input_type','required','source_schema']] for non-stale fields, 'source_piece' => ['id','width','height']|null]`. `execution_snapshot` is never updated after insert; the `Generation` model guards it with a `saving` hook that throws if the attribute is dirty on an existing row. Attaches `generation_inputs` for every `__upload` reference. Dispatches `RunGenerationJob` after commit (`DB::afterCommit`).
- `SnapshotExpander::expand(Generation $g): array` — replaces `['__upload' => id]` with `InputUploadService::dataUrl` and `['__piece' => id]` with the piece bytes from disk `pieces` as data URL; throws `RuntimeException("La solicitud supera el tamaño máximo permitido.")` if the JSON payload exceeds `media.max_request_bytes`.
- `PollGenerationJob(int $generationJobId)` (`ShouldQueue`, `$tries = 1`, `$timeout = 60`) is **created in this task as a stub** with the constructor and an empty `handle(EngineResolver $engines): void` so `RunGenerationJob` can dispatch it; Task 16 fills it in.
- All jobs call `Log::withContext(['generation_id' => …, 'brand_id' => …, 'pipeline_id' => …])` at the start of `handle` and log `info` on each status transition and `warning` on failures with the sanitized message; never the inputs.
- Workers load the campaign with `Campaign::withTrashed()` so accepted work on a soft-deleted campaign still finishes.
- `RunGenerationJob` (`ShouldQueue`, `$tries = 1`, `$timeout = 90`): claim `UPDATE generations SET status='submitting', submission_started_at=NOW() WHERE id=? AND status='pending'`; if 0 rows affected → return (log info "claim already taken"). Then expand, `EngineResolver->forSource(brand, snapshot.credential_source)->submit(provider_ref, inputs)`. `accepted` → create `generation_jobs` rows, `status = submitted`, `submitted_at`, dispatch `PollGenerationJob` per job with 4 s delay. `rejected` → `failed`, `failure_reason = provider_failed`, `error_message`. `unknown` → `failed`, `submission_unknown`, `error_message = "No pudimos confirmar si el trabajo se inició."`, `retryable = true`. Expansion `RuntimeException` → `failed`, `provider_failed`, its message.

- [ ] **Step 1: Failing tests**

```php
// CreateGenerationTest
function readyGenerator(Campaign $c): Pipeline {
    $p = Pipeline::factory()->for($c)->create(['kind' => PipelineKind::Generator, 'is_active' => true, 'readiness_errors' => [], 'input_schema' => ['properties' => []], 'config_revision' => 3]);
    PipelineField::factory()->for($p)->create(['name' => 'describe_la_escena', 'input_type' => InputType::String, 'role' => FieldRole::Prompt, 'required' => true]);
    return $p->refresh();
}

it('creates a pending series generation with a snapshot and dispatches the run job', function () {
    Queue::fake(); $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = readyGenerator($c);
    $g = app(CreateGeneration::class)->series($user, $c, $p, ['describe_la_escena' => 'taller'], (string) Str::uuid(), 3);
    expect($g->status)->toBe(GenerationStatus::Pending)->and($g->execution_snapshot['inputs'])->toBe(['describe_la_escena' => 'taller'])
        ->and($g->execution_snapshot['credential_source'])->toBe('studio')->and($g->execution_snapshot['config_revision'])->toBe(3);
    Queue::assertPushed(RunGenerationJob::class);
});

it('is idempotent per request id and rejects stale revisions and foreign brands', function () {
    Queue::fake(); $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = readyGenerator($c); $rid = (string) Str::uuid();
    $a = app(CreateGeneration::class)->series($user, $c, $p, ['describe_la_escena' => 'x'], $rid, 3);
    $b = app(CreateGeneration::class)->series($user, $c, $p, ['describe_la_escena' => 'x'], $rid, 3);
    expect($a->id)->toBe($b->id);
    expect(fn () => app(CreateGeneration::class)->series($user, $c, $p, ['describe_la_escena' => 'x'], (string) Str::uuid(), 2))->toThrow(ValidationException::class);
    $outsider = User::factory()->editor()->create();
    expect(fn () => app(CreateGeneration::class)->series($outsider, $c, $p, ['describe_la_escena' => 'x'], (string) Str::uuid(), 3))->toThrow(AuthorizationException::class);
});

it('refuses new work on a soft-deleted campaign but lets accepted work finish', function () {
    Queue::fake(); $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = readyGenerator($c);
    $g = app(CreateGeneration::class)->series($user, $c, $p, ['describe_la_escena' => 'x'], (string) Str::uuid(), 3);
    $c->delete();
    expect(fn () => app(CreateGeneration::class)->series($user, $c->fresh(), $p, ['describe_la_escena' => 'x'], (string) Str::uuid(), 3))->toThrow(ValidationException::class);
    fakeEngine()->willAccept(['j1']);
    (new RunGenerationJob($g->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));
    expect($g->fresh()->status)->toBe(GenerationStatus::Submitted);
});

it('never lets the snapshot change after insert', function () {
    $g = Generation::factory()->create();
    $g->execution_snapshot = snapshot(['inputs' => ['x' => 1]]);
    expect(fn () => $g->save())->toThrow(LogicException::class);
});

it('rejects a ready pipeline from another campaign of the same brand', function () {
    Queue::fake(); $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c1 = Campaign::factory()->for($brand)->create(); $c2 = Campaign::factory()->for($brand)->create(); $p2 = readyGenerator($c2);
    expect(fn () => app(CreateGeneration::class)->series($user, $c1, $p2, ['describe_la_escena' => 'x'], (string) Str::uuid(), 3))->toThrow(ValidationException::class);
});

// RunGenerationJobTest
it('claims, submits once, stores all job ids and schedules polling', function () {
    Queue::fake([PollGenerationJob::class]); $engine = fakeEngine()->willAccept(['j1', 'j2']);
    $g = Generation::factory()->create(['status' => 'pending', 'execution_snapshot' => snapshot(['inputs' => ['describe_la_escena' => 'x']])]);
    (new RunGenerationJob($g->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));
    (new RunGenerationJob($g->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class)); // redelivery
    expect($engine->submissions)->toHaveCount(1)->and($g->fresh()->status)->toBe(GenerationStatus::Submitted)->and($g->jobs()->pluck('provider_job_id')->all())->toBe(['j1', 'j2']);
    Queue::assertPushed(PollGenerationJob::class, 2);
});

it('marks unknown outcomes as submission_unknown and never resubmits', function () {
    $engine = fakeEngine()->willBeUnknown();
    $g = Generation::factory()->create(['status' => 'pending', 'execution_snapshot' => snapshot()]);
    (new RunGenerationJob($g->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));
    expect($g->fresh()->status)->toBe(GenerationStatus::Failed)->and($g->fresh()->failure_reason)->toBe(FailureReason::SubmissionUnknown)->and($g->fresh()->retryable)->toBeTrue();
    (new RunGenerationJob($g->id))->handle(app(EngineResolver::class), app(SnapshotExpander::class));
    expect($engine->submissions)->toHaveCount(1);
});

it('expands piece and upload references into data urls', function () {
    Storage::fake('pieces'); Storage::fake('inputs');
    $piece = Piece::factory()->create(['storage_path' => 'a/b.png', 'mime_type' => 'image/png']); Storage::disk('pieces')->put('a/b.png', 'PNGBYTES');
    $g = Generation::factory()->create(['execution_snapshot' => snapshot(['inputs' => ['foto' => ['__piece' => $piece->id], 'txt' => 'hola']])]);
    expect(app(SnapshotExpander::class)->expand($g))->toBe(['foto' => 'data:image/png;base64,' . base64_encode('PNGBYTES'), 'txt' => 'hola']);
});
```
Run → FAIL. **Step 2: Implement** as specified (`RunGenerationJob::__construct(public int $generationId)`; `handle(EngineResolver $engines, SnapshotExpander $expander)`; `GenerationFactory` must create a campaign with a brand). **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: create generation with snapshot and single-claim submission job"`

### Task 16: PollGenerationJob, DownloadOutputJob, completion, 4K validation, RestartGeneration

**Files:** Create `app/app/Jobs/PollGenerationJob.php`, `app/app/Jobs/DownloadOutputJob.php`, `app/app/Services/Generation/GenerationStateMachine.php`, `RestartGeneration.php`. Test: `tests/Feature/Generation/PollAndDownloadTest.php`, `tests/Feature/Generation/RestartGenerationTest.php`.

**Interfaces — Produces:**
- `PollGenerationJob(int $generationJobId)` (`$tries = 1`, `$timeout = 60`): loads the job row; **eligibility is per child**: if the job row itself is terminal → return (the generation's status is irrelevant, so late recovery of one child never disables polling of another). **Check elapsed time first:** if `now() - submitted_at >= 10 min` and the job is still non-terminal → `GenerationStateMachine::timeout($generation)` and return (no further automatic polls; `checkStatus` re-arms them). Then `inspect(provider_job_id)`. A `KreaException` with status **401 or 404** is permanent → `childFailed($job, 'provider_failed', message)`. Any other `KreaException`/`ConnectionException` → `poll_failures++`, re-dispatch with delay `[15, 30, 60][min(poll_failures-1, 2)]` s (the elapsed check above bounds the total). Store `status`, `normalized_status`, `queue_position`, `result`, `error`, `last_polled_at`; generation `status = processing` if currently `submitted`. Pending → re-dispatch with delay: 4 s if `< 2 min` since `submitted_at`, 8 s if `< 5 min`, 15 s otherwise. Completed → `outputs()` → if empty: `childFailed($job, 'invalid_result')`; else **upsert** `generation_outputs` rows keyed by `(generation_job_id, index)` where `index` is the position inside this job's result (replay is idempotent), generation `status = downloading`, dispatch `DownloadOutputJob` for each output still `pending`. Failed/cancelled → `childFailed($job, 'provider_failed')`. After any terminal child: `GenerationStateMachine::settle($generation)`.
- `DownloadOutputJob(int $outputId)` (`$tries = 1`, `$timeout = 120`): first **claims** the output with `UPDATE generation_outputs SET status='downloading', attempts = attempts + 1 WHERE id=? AND status IN ('pending','failed')`; 0 rows affected (already stored or being downloaded) → return, so replays are no-ops and never overwrite a delivered asset. Then `ResultDownloader->download(source_url)`. On `DownloadException`: if `attempts < 3` set `status = pending`, `next_attempt_at`, and re-dispatch with delay `[5, 15][attempts-1]` s (three attempts total), else `status = failed`, `error_message`. On `InvalidImageException`: `status = failed` immediately with reason `invalid_result` recorded on the generation via `GenerationStateMachine::outputInvalid($output)`. On success: store to `pieces` disk at `{brand}/{campaign}/{generation}/{output_id}.{ext}`, create `Piece` (`updateOrCreate` by `generation_output_id`) with `kind` from generation kind (`series → original`, `edit → edit`, `upscale → upscale`), `parent_piece_id` = generation `parent_piece_id`, `root_piece_id = parent ? (parent->root_piece_id ?? parent->id) : null`, dims, `is_4k` = kind upscale && `FourKRule::accepts(snapshot.source_piece.width, .height, w, h)`; output `status = stored`. Then `settle()`.
- `GenerationStateMachine::settle(Generation $g)`: with row lock; if any job non-terminal → nothing. If any output pending → nothing. Compute: `storedCount`, `failedOutputs`, `failedJobs`. Completed when `storedCount > 0 && failedOutputs == 0 && failedJobs == 0` **and**, for kind upscale, every stored piece `is_4k` (else `failed`, `delivery_dimensions`, `"La imagen recibida no cumple las dimensiones de entrega en 4K."`). Otherwise `failed` with reason `invalid_result` if any output failed with an invalid image, else `download_failed` if any output failed to download (`retryable = true`), else `provider_failed`/`invalid_result` from the child, message `El proceso terminó con estado "{native}".` for provider failures. Sets `completed_at`. `timeout($g)`: `failed`, `poll_timeout`, `retryable = true`, message `"Se agotó el tiempo de espera. El trabajo podría seguir en curso."`. `childFailed($job, $reason)` records on the job and calls `settle`.
- `RestartGeneration::checkStatus(User $user, Generation $g): Generation` — recovery, never submits: re-authorizes brand membership only (a deactivated pipeline must not block retrieving accepted work), resets `submitted_at` bookkeeping for the timeout window (`recovery_started_at` kept in memory, not a column: the re-armed polls use a fresh 10 min window measured from now), re-dispatches `PollGenerationJob` for every non-terminal job and `DownloadOutputJob` for every `pending`/`failed` output with `attempts` reset to 0. Used by the "Comprobar estado" and "Reintentar descarga" actions.
- `RestartGeneration::confirmRestart(User $user, Generation $original, string $restartRequestId): Generation` — eligible only when `original->status === Failed && original->retryable`, else `ValidationException("Este trabajo no admite reintento.")`. Re-authorizes: brand membership, the parent piece (edits/upscales) still belongs to the campaign, every `__upload` in the snapshot still passes `authorize`/`authorizeForPipeline`. For `poll_timeout` originals it first inspects each known job synchronously: if **every** job completed, it dispatches downloads and returns the original (no new execution); if **some** completed, their outputs are recorded and downloaded **and** the restart proceeds for the request as a whole (partial results stay accessible on the original). A new execution additionally requires the pipeline to be active and ready (`"Esta campaña no tiene {kind} configurado."`). Then creates a new `pending` Generation copying `execution_snapshot`, `kind`, `parent_piece_id`, `pipeline_id`, with `restarted_from_generation_id`, `request_id = restartRequestId` (idempotent), and dispatches `RunGenerationJob`.

- [ ] **Step 1: Failing tests**

```php
it('polls to completion, writes the manifest, downloads, creates pieces and completes', function () {
    Storage::fake('pieces'); Queue::fake([DownloadOutputJob::class]);
    $engine = fakeEngine(); $png = file_get_contents(base_path('tests/Fixtures/images/tiny.png'));
    Http::fake(['https://cdn.test/*' => Http::response($png, 200)]);
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $job = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);
    $engine->setJob('j1', new JobObservation('completed', 'completed', null, ['urls' => ['https://cdn.test/1.png', 'https://cdn.test/2.png']], null));
    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class));
    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class)); // replay of a completed child must not add outputs
    expect($g->fresh()->status)->toBe(GenerationStatus::Downloading)->and($g->outputs()->count())->toBe(2);
    Queue::assertPushed(DownloadOutputJob::class, 2);
    foreach ($g->outputs as $o) { (new DownloadOutputJob($o->id))->handle(app(ResultDownloader::class)); }
    expect($g->fresh()->status)->toBe(GenerationStatus::Completed)->and($g->pieces()->count())->toBe(2)->and($g->pieces()->first()->kind)->toBe(PieceKind::Original);
});

it('marks a permanent 404 on inspect as a provider failure and keeps polling siblings', function () {
    Queue::fake([PollGenerationJob::class]); $engine = fakeEngine();
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
    $gone = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'gone']); $alive = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'alive']);
    $engine->failInspect('gone', new \App\Engines\KreaException('No se encontró el flujo.', 404));
    (new PollGenerationJob($gone->id))->handle(app(EngineResolver::class)); (new PollGenerationJob($alive->id))->handle(app(EngineResolver::class));
    expect($gone->fresh()->normalized_status)->toBe('failed')->and($g->fresh()->status)->toBe(GenerationStatus::Processing);
    Queue::assertPushed(PollGenerationJob::class, fn ($j) => $j->generationJobId === $alive->id);
});

it('does not download twice for the same output', function () {
    Storage::fake('pieces'); Http::fake(['https://cdn.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')), 200)]);
    $g = Generation::factory()->create(['status' => 'downloading']); $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $o = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/1.png']);
    (new DownloadOutputJob($o->id))->handle(app(ResultDownloader::class)); (new DownloadOutputJob($o->id))->handle(app(ResultDownloader::class));
    Http::assertSentCount(1); expect($g->pieces()->count())->toBe(1)->and($o->fresh()->attempts)->toBe(1);
});

it('backs off on pending and times out after ten minutes without resubmitting', function () {
    Queue::fake([PollGenerationJob::class]); $engine = fakeEngine();
    $g = Generation::factory()->create(['status' => 'submitted', 'submitted_at' => now()->subMinutes(11)]);
    $job = GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);
    (new PollGenerationJob($job->id))->handle(app(EngineResolver::class));
    expect($g->fresh()->status)->toBe(GenerationStatus::Failed)->and($g->fresh()->failure_reason)->toBe(FailureReason::PollTimeout)->and($engine->submissions)->toBe([]);
});

it('fails download after three attempts but keeps the manifest row and sibling pieces', function () {
    Storage::fake('pieces'); Queue::fake([DownloadOutputJob::class]);
    Http::fake(['https://cdn.test/bad.png' => Http::response('', 500), 'https://cdn.test/ok.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')), 200)]);
    $g = Generation::factory()->create(['status' => 'downloading']); $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $ok = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 0, 'source_url' => 'https://cdn.test/ok.png']);
    $bad = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['index' => 1, 'source_url' => 'https://cdn.test/bad.png', 'attempts' => 2]); // third and last attempt
    (new DownloadOutputJob($ok->id))->handle(app(ResultDownloader::class)); (new DownloadOutputJob($bad->id))->handle(app(ResultDownloader::class));
    expect($bad->fresh()->status)->toBe(OutputStatus::Failed)->and($bad->fresh()->source_url)->toBe('https://cdn.test/bad.png')
        ->and($g->fresh()->status)->toBe(GenerationStatus::Failed)->and($g->fresh()->failure_reason)->toBe(FailureReason::DownloadFailed)->and($g->fresh()->retryable)->toBeTrue()->and($g->pieces()->count())->toBe(1);
});

it('classifies a non-image body as invalid_result', function () {
    Storage::fake('pieces'); Http::fake(['https://cdn.test/t' => Http::response('not an image', 200)]);
    $g = Generation::factory()->create(['status' => 'downloading']); $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $o = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/t']);
    (new DownloadOutputJob($o->id))->handle(app(ResultDownloader::class));
    expect($g->fresh()->failure_reason)->toBe(FailureReason::InvalidResult);
});

it('sets is_4k only when the upscale output matches the exact target', function () {
    Storage::fake('pieces'); $src = Piece::factory()->create(['width' => 1920, 'height' => 1080]);
    $g = Generation::factory()->create(['kind' => 'upscale', 'parent_piece_id' => $src->id, 'status' => 'downloading', 'execution_snapshot' => snapshot(['kind' => 'upscale', 'source_piece' => ['id' => $src->id, 'width' => 1920, 'height' => 1080]])]);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $o = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/up.png']);
    $img = imagecreatetruecolor(3840, 2160); ob_start(); imagepng($img); $bytes = ob_get_clean();
    Http::fake(['https://cdn.test/up.png' => Http::response($bytes, 200)]);
    (new DownloadOutputJob($o->id))->handle(app(ResultDownloader::class));
    $piece = $g->pieces()->first();
    expect($piece->is_4k)->toBeTrue()->and($piece->kind)->toBe(PieceKind::Upscale)->and($piece->root_piece_id)->toBe($src->id)->and($g->fresh()->status)->toBe(GenerationStatus::Completed);
});

it('fails an upscale whose output misses the target but keeps the piece', function () {
    Storage::fake('pieces'); $src = Piece::factory()->create(['width' => 1920, 'height' => 1080]);
    $g = Generation::factory()->create(['kind' => 'upscale', 'parent_piece_id' => $src->id, 'status' => 'downloading', 'execution_snapshot' => snapshot(['kind' => 'upscale', 'source_piece' => ['id' => $src->id, 'width' => 1920, 'height' => 1080]])]);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'completed']);
    $o = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['source_url' => 'https://cdn.test/small.png']);
    Http::fake(['https://cdn.test/small.png' => Http::response(file_get_contents(base_path('tests/Fixtures/images/tiny.png')), 200)]);
    (new DownloadOutputJob($o->id))->handle(app(ResultDownloader::class));
    expect($g->fresh()->failure_reason)->toBe(FailureReason::DeliveryDimensions)->and($g->pieces()->count())->toBe(1)->and($g->pieces()->first()->is_4k)->toBeFalse();
});

// RestartGenerationTest
it('recovers a completed original instead of launching a new execution', function () {
    Queue::fake(); $engine = fakeEngine()->setJob('j1', new JobObservation('completed', 'completed', null, ['urls' => ['https://cdn.test/a.png']], null));
    $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = Pipeline::factory()->for($c)->create(['is_active' => true, 'readiness_errors' => []]);
    $g = Generation::factory()->for($c)->for($p)->for($user)->create(['status' => 'failed', 'failure_reason' => 'poll_timeout', 'retryable' => true]);
    GenerationJob::factory()->for($g)->create(['provider_job_id' => 'j1']);
    $result = app(RestartGeneration::class)->confirmRestart($user, $g, (string) Str::uuid());
    expect($result->id)->toBe($g->id)->and($engine->submissions)->toBe([]);
    Queue::assertPushed(DownloadOutputJob::class);
});

it('creates one linked replacement per confirmation and copies the snapshot', function () {
    Queue::fake(); fakeEngine();
    $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = Pipeline::factory()->for($c)->create(['is_active' => true, 'readiness_errors' => []]);
    $g = Generation::factory()->for($c)->for($p)->for($user)->create(['status' => 'failed', 'failure_reason' => 'submission_unknown', 'retryable' => true, 'execution_snapshot' => snapshot(['inputs' => ['x' => 1]])]);
    $rid = (string) Str::uuid();
    $a = app(RestartGeneration::class)->confirmRestart($user, $g, $rid); $b = app(RestartGeneration::class)->confirmRestart($user, $g, $rid);
    expect($a->id)->toBe($b->id)->and($a->restarted_from_generation_id)->toBe($g->id)->and($a->execution_snapshot)->toBe(snapshot(['inputs' => ['x' => 1]]))->and($a->status)->toBe(GenerationStatus::Pending);
    Queue::assertPushed(RunGenerationJob::class, 1);
});

it('refuses restart of a non-retryable generation and re-checks the parent piece', function () {
    Queue::fake(); fakeEngine();
    $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = Pipeline::factory()->for($c)->create(['kind' => 'editor', 'is_active' => true, 'readiness_errors' => []]);
    $done = Generation::factory()->for($c)->for($p)->for($user)->create(['status' => 'completed']);
    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $done, (string) Str::uuid()))->toThrow(ValidationException::class);
    $foreignPiece = Piece::factory()->create();
    $edit = Generation::factory()->for($c)->for($p)->for($user)->create(['kind' => 'edit', 'parent_piece_id' => $foreignPiece->id, 'status' => 'failed', 'failure_reason' => 'submission_unknown', 'retryable' => true]);
    expect(fn () => app(RestartGeneration::class)->confirmRestart($user, $edit, (string) Str::uuid()))->toThrow(AuthorizationException::class);
});

it('checkStatus re-arms polling and download retries without submitting', function () {
    Queue::fake(); $engine = fakeEngine();
    $brand = Brand::factory()->create(); $user = User::factory()->editor()->create(); $brand->users()->attach($user);
    $c = Campaign::factory()->for($brand)->create(); $p = Pipeline::factory()->for($c)->create(['is_active' => false]);
    $g = Generation::factory()->for($c)->for($p)->for($user)->create(['status' => 'failed', 'failure_reason' => 'poll_timeout', 'retryable' => true]);
    $job = GenerationJob::factory()->for($g)->create(['normalized_status' => 'pending']);
    $out = GenerationOutput::factory()->for($g)->for($job, 'job')->create(['status' => 'failed', 'attempts' => 3]);
    app(RestartGeneration::class)->checkStatus($user, $g);
    Queue::assertPushed(PollGenerationJob::class, 1); Queue::assertPushed(DownloadOutputJob::class, 1);
    expect($out->fresh()->attempts)->toBe(0)->and($engine->submissions)->toBe([]);
});
```
Run → FAIL. **Step 2: Implement** as specified; all status writes go through `GenerationStateMachine` methods using `DB::transaction` + `lockForUpdate()`. Add `FakeEngine::failInspect(string $jobId, \Throwable $e)` so `inspect()` throws for that job. **Step 3: Run** the whole suite → PASS. **Step 4: Commit** — `git commit -m "feat: polling, output download, completion rules, 4K validation and restart"`

### Task 16b: `media:reconcile` — stale claims and lost dispatches (minimal)

**Files:** Create `app/app/Console/Commands/MediaReconcile.php`; schedule in `routes/console.php` (`->everyMinute()->withoutOverlapping()`). Test: `tests/Feature/Generation/MediaReconcileTest.php`.

**Interfaces — Produces:** `php artisan media:reconcile` does exactly three things, never calling `submit`:
1. `submitting` generations with `submission_started_at < now() - 120 s` → `failed`, `submission_unknown`, `retryable = true`, message "No pudimos confirmar si el trabajo se inició." (the claim is never cleared back to `pending`).
2. `pending` generations older than 60 s → dispatch `RunGenerationJob` again (the claim makes a duplicate dispatch harmless).
3. Non-terminal `generation_jobs` whose `next_poll_at < now() - 120 s` (or null and generation `submitted`/`processing` for > 120 s) → dispatch `PollGenerationJob`; `generation_outputs` in `pending` with `next_attempt_at < now() - 120 s`, or in `downloading` for > 300 s (a crashed download) → reset to `pending` and dispatch `DownloadOutputJob`.
It prints one line per action and exits 0. Nothing here polls beyond the 10 min window or touches terminal generations except rule 1.

- [ ] **Step 1: Failing test**
```php
it('marks stale submitting claims unknown, redispatches stuck pending rows, and re-arms lost polls and downloads', function () {
    Queue::fake();
    $stale = Generation::factory()->create(['status' => 'submitting', 'submission_started_at' => now()->subSeconds(200)]);
    $fresh = Generation::factory()->create(['status' => 'submitting', 'submission_started_at' => now()->subSeconds(30)]);
    $stuckPending = Generation::factory()->create(['status' => 'pending', 'created_at' => now()->subMinutes(2)]);
    $polling = Generation::factory()->create(['status' => 'processing', 'submitted_at' => now()->subMinutes(3)]);
    $lostJob = GenerationJob::factory()->for($polling)->create(['normalized_status' => 'pending', 'next_poll_at' => now()->subMinutes(3)]);
    $crashed = GenerationOutput::factory()->for($polling)->for($lostJob, 'job')->create(['status' => 'downloading', 'updated_at' => now()->subMinutes(6)]);
    $this->artisan('media:reconcile')->assertExitCode(0);
    expect($stale->fresh()->status)->toBe(GenerationStatus::Failed)->and($stale->fresh()->failure_reason)->toBe(FailureReason::SubmissionUnknown)
        ->and($fresh->fresh()->status)->toBe(GenerationStatus::Submitting)->and($crashed->fresh()->status)->toBe(OutputStatus::Pending);
    Queue::assertPushed(RunGenerationJob::class, fn ($j) => $j->generationId === $stuckPending->id);
    Queue::assertPushed(PollGenerationJob::class, fn ($j) => $j->generationJobId === $lostJob->id);
    Queue::assertPushed(DownloadOutputJob::class, fn ($j) => $j->outputId === $crashed->id);
    expect(fakeEngine()->submissions)->toBe([]);
});
```
Run → FAIL. **Step 2: Implement.** **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: minimal media:reconcile for stale claims and lost dispatches"`

---

## Phase 6 — Admin panel (Art Directors)

### Task 17: Panel access, User interfaces, seeding an Art Director

**Files:** Modify `app/app/Models/User.php`, `app/app/Providers/Filament/AdminPanelProvider.php`, `AppPanelProvider.php`; create `database/seeders/ArtDirectorSeeder.php`. Test: `tests/Feature/Panels/PanelAccessTest.php`.

**Interfaces — Produces:**
- `User implements FilamentUser, HasTenants`: `canAccessPanel(Panel $panel): bool` → `admin` panel requires `isArtDirector()`, `app` panel requires `role === Editor`; `getTenants(Panel $panel): Collection` → `$this->brands`; `canAccessTenant(Model $tenant): bool` → `$this->brands()->whereKey($tenant->getKey())->exists()`.
- `AdminPanelProvider`: id `admin`, path `admin`, `->login()`, `->brandName('Media Ops · Estudio')`, discovers resources in `app/Filament/Admin/Resources`.
- `AppPanelProvider`: id `app`, path `app`, `->login()`, `->brandName('Media Ops')`, `->brandLogo(fn () => ($t = Filament::getTenant()) && $t->logo_path ? route('media.logo', $t) : null)` (route from Task 20; returns null until then), `->tenant(Brand::class, slugAttribute: 'slug')`, `->tenantMenu(fn () => auth()->user()->brands()->count() > 1)`, discovers pages in `app/Filament/App/Pages`, `->spa()`. The jobs-bell render hook is added in Task 22, when the component exists.
- Both panels: `->colors(['primary' => Color::Red])`, `->locale` handled by `APP_LOCALE=es`; Filament's Spanish translations ship with the package.

- [ ] **Step 1: Failing test**

```php
it('lets art directors into admin only and editors into app only', function () {
    $ad = User::factory()->artDirector()->create(); $ed = User::factory()->editor()->create(); $brand = Brand::factory()->create(); $brand->users()->attach($ed);
    $this->actingAs($ad)->get('/admin')->assertOk();
    $this->actingAs($ed)->get('/admin')->assertForbidden();
    $this->actingAs($ed)->get("/app/{$brand->slug}")->assertOk();
    $this->actingAs($ad)->get("/app/{$brand->slug}")->assertForbidden();
});

it('blocks an editor from a brand they do not belong to', function () {
    $ed = User::factory()->editor()->create(); $mine = Brand::factory()->create(); $mine->users()->attach($ed); $other = Brand::factory()->create();
    $this->actingAs($ed)->get("/app/{$other->slug}")->assertNotFound();
});
```
Run → FAIL. **Step 2: Implement** the interfaces and providers. Create `ArtDirectorSeeder` that upserts `ad@picante.local` / password from `env('SEED_AD_PASSWORD', 'password')` with role `art_director`; register in `DatabaseSeeder`. **Step 3: Run** → PASS; also `php artisan db:seed` then log into `/admin` manually once. **Step 4: Commit** — `git commit -m "feat: panel access rules, brand tenancy, art director seeder"`

### Task 18: Brand and User resources

**Files:** Create `app/app/Filament/Admin/Resources/BrandResource.php` (+ `Pages/`), `UserResource.php` (+ `Pages/`). Test: `tests/Feature/Admin/BrandResourceTest.php`, `UserResourceTest.php`.

Before writing each resource, run Blueprint's planning guidance for that resource (installed in Task 1) and follow its conventions; then implement exactly the behavior below.

**Behavior:**
- Brands form: `TextInput name` required, `TextInput slug` required unique (auto from name), `FileUpload logo_path` (`disk('pieces')`, directory `brands`, image, private), `TextInput krea_api_key` `->password()->revealable(false)->dehydrated(fn ($state) => filled($state))->helperText('Se guarda cifrada. Escribe una nueva para reemplazarla.')` plus a `Toggle use_studio_key` labelled `"Usar la clave del estudio"` (not a column; `->dehydrated(false)`, default = `krea_api_key` is null) whose `true` state on save sets `krea_api_key = null` in `mutateFormDataBeforeSave`. A read-only `TextEntry` shows `"Clave propia configurada"` or `"Usa la clave del estudio"`. Table: name, slug, users count, campaigns count. Header action on edit page **"Probar conexión"**: resolves `EngineResolver->forBrand($record)` and calls `describe` against `GET /node-apps?limit=1` equivalent — implement as `KreaEngine::ping(): void` (add to `KreaEngine`, not the interface: `$this->http(15)->get('/node-apps', ['limit' => 1])->throw()`), notification success `"Conexión correcta."` or danger with the mapped message.
- Users form: name, email, `password` (hashed, required on create only), `Select role` options `['art_director' => 'Director de arte', 'editor' => 'Editor']`, `Select brands` multiple relationship `brands` visible when role is editor. Table: name, email, role badge, brands.

- [ ] **Step 1: Failing tests** (Filament resource tests with Livewire)
```php
it('creates a brand with an encrypted key that is never shown back', function () {
    $this->actingAs(User::factory()->artDirector()->create());
    Livewire::test(CreateBrand::class)->fillForm(['name' => 'Santander', 'slug' => 'santander', 'krea_api_key' => 'k-secret'])->call('create')->assertHasNoFormErrors();
    $brand = Brand::first();
    expect($brand->krea_api_key)->toBe('k-secret')->and(DB::table('brands')->value('krea_api_key'))->not->toContain('k-secret');
    Livewire::test(EditBrand::class, ['record' => $brand->id])->assertFormSet(['krea_api_key' => null]);
    Livewire::test(EditBrand::class, ['record' => $brand->id])->fillForm(['use_studio_key' => true])->call('save')->assertHasNoFormErrors();
    expect($brand->fresh()->krea_api_key)->toBeNull()->and($brand->fresh()->resolveKreaKeySource())->toBe('studio');
});
it('assigns brands to an editor', function () {
    $this->actingAs(User::factory()->artDirector()->create()); $b = Brand::factory()->create();
    Livewire::test(CreateUser::class)->fillForm(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => 'secret123', 'role' => 'editor', 'brands' => [$b->id]])->call('create')->assertHasNoFormErrors();
    expect(User::where('email', 'ana@x.com')->first()->brands)->toHaveCount(1);
});
```
Run → FAIL. **Step 2: Implement.** For the edit form, load `krea_api_key` as `null` via `mutateFormDataBeforeFill` so the secret is never rendered. **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: brand and user admin resources"`

### Task 19: Campaign resource with Pipelines and Generations relation managers, Pipeline resource with field editor

**Files:** Create `CampaignResource.php` (+ `Pages/`, `RelationManagers/PipelinesRelationManager.php`, `RelationManagers/GenerationsRelationManager.php`), `PipelineResource.php` (+ `Pages/EditPipeline.php`, `RelationManagers/FieldsRelationManager.php`). Test: `tests/Feature/Admin/CampaignResourceTest.php`, `PipelineResourceTest.php`.

**Behavior:**
- Campaigns form: `Select brand_id` relationship, name, slug, description, `FileUpload cover_path` (`pieces` disk, dir `covers`), `DatePicker starts_on/ends_on`, `Select default_pipeline_id` options = the campaign's active generators (edit only), saved through `PipelineActivation::setDefault`. Table: brand, name, pipelines count, pieces count. Soft-delete actions. **No status field.**
- PipelinesRelationManager: table label, kind badge, `provider_ref`, `is_active` icon, readiness (icon: check when `readiness_errors` empty, warning otherwise with tooltip listing errors). Create action: kind select (`Generador`, `Editor`, `Upscaler`), label, `provider_ref`, sort order → `afterCreate`: run `PipelineSchemaSync`; on `KreaException` delete the record and re-throw as `ValidationException` on `provider_ref` with the Krea message. Row actions: **"Refrescar esquema"** (sync, notify), **"Activar"** / **"Desactivar"** (via `PipelineActivation`, surfacing `ValidationException` messages as danger notifications), **"Editar campos"** (link to `EditPipeline`).
- EditPipeline page: read-only label/kind/provider_ref/config_revision/readiness errors list; `FieldsRelationManager` table over `fields` (non-stale first) with **read-only** columns `name`, `required` (icon), `source_schema` (`TextColumn` showing the JSON type, with the full schema JSON in a tooltip), `needs_configuration` (warning icon), and an edit action opening a form with: `input_type` select, `label_override`, `help_text`, `visibility` select, `has_fixed_value` toggle, `fixed_value` (for `image` type a `FileUpload` on disk `inputs` dir `tmp`; for other types a `TextInput` parsed as JSON when valid JSON, else string), `role` select. On save: an image fixed value is finalized through `InputUploadService::finalize($path, $pipeline->campaign->brand, auth()->user())`, stored as `['__upload' => id]`, and linked in `pipeline_inputs` (previous link removed when replaced); the field's `needs_configuration` is cleared; then `PipelineReadiness::evaluate` runs, errors are stored, `config_revision++`, and an active pipeline with errors is deactivated via `PipelineActivation::deactivate`. Header text: `"Los campos vinculados (prompt/imagen) no pueden tener valor fijo."`
- GenerationsRelationManager (read-only): user, pipeline label, kind, status badge, `failure_reason`, error message, provider job IDs (joined), created/completed. Filter by status.

- [ ] **Step 1: Failing tests**
```php
it('creates a pipeline through the relation manager and syncs its schema', function () {
    $this->actingAs(User::factory()->artDirector()->create()); fakeEngine()->withSchema('ver-1', ['type' => 'object', 'required' => ['describe_la_escena'], 'properties' => ['describe_la_escena' => ['type' => 'string']]], 'Creador');
    $c = Campaign::factory()->create();
    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditCampaign::class])
        ->callTableAction('create', data: ['kind' => 'generator', 'label' => 'Creador', 'provider_ref' => 'ver-1', 'sort_order' => 1])->assertHasNoTableActionErrors();
    $p = $c->pipelines()->first();
    expect($p->fields)->toHaveCount(1)->and($p->is_active)->toBeFalse()->and($p->readiness_errors)->toBe([]);
});
it('surfaces a krea failure on create and saves nothing', function () {
    $this->actingAs(User::factory()->artDirector()->create()); fakeEngine(); $c = Campaign::factory()->create();
    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditCampaign::class])
        ->callTableAction('create', data: ['kind' => 'generator', 'label' => 'X', 'provider_ref' => 'missing', 'sort_order' => 1])->assertHasTableActionErrors(['provider_ref']);
    expect($c->pipelines()->count())->toBe(0);
});
it('stores an image fixed value as an owned upload linked to the pipeline and clears needs_configuration', function () {
    Storage::fake('inputs'); Storage::disk('inputs')->put('tmp/base.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $this->actingAs(User::factory()->artDirector()->create()); $p = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $f = PipelineField::factory()->for($p)->create(['name' => 'base', 'input_type' => 'image', 'visibility' => 'hidden', 'required' => true, 'needs_configuration' => true]);
    Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $p, 'pageClass' => EditPipeline::class])
        ->callTableAction('edit', $f, data: ['has_fixed_value' => true, 'fixed_value' => 'tmp/base.png'])->assertHasNoTableActionErrors();
    $f->refresh();
    expect($f->fixed_value)->toHaveKey('__upload')->and($f->needs_configuration)->toBeFalse()
        ->and(DB::table('pipeline_inputs')->where('pipeline_id', $p->id)->count())->toBe(1)->and($p->fresh()->config_revision)->toBe(2);
});

it('activates a ready pipeline and refuses a second active editor', function () {
    $this->actingAs(User::factory()->artDirector()->create()); $c = Campaign::factory()->create();
    $mk = function () use ($c) { $p = Pipeline::factory()->for($c)->create(['kind' => 'editor', 'input_schema' => ['properties' => []]]);
        PipelineField::factory()->for($p)->create(['name' => 'img', 'input_type' => 'image', 'role' => 'image']); PipelineField::factory()->for($p)->create(['name' => 'txt', 'input_type' => 'string', 'role' => 'prompt']); return $p; };
    [$a, $b] = [$mk(), $mk()];
    $lw = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditCampaign::class]);
    $lw->callTableAction('activate', $a); expect($a->fresh()->is_active)->toBeTrue();
    $lw->callTableAction('activate', $b)->assertNotified(); expect($b->fresh()->is_active)->toBeFalse();
});
```
Run → FAIL. **Step 2: Implement.** **Step 3: Run** → PASS; also click through create → refresh → edit fields → activate in the browser against MinIO/FakeEngine-less dev (needs a real key; if none yet, verify the error path). **Step 4: Commit** — `git commit -m "feat: campaign and pipeline admin resources with field editor and activation"`

---

## Phase 7 — App panel (Editors)

### Task 20: Campañas page and media URL endpoint

**Files:** Create `app/app/Filament/App/Pages/Campaigns.php` + `resources/views/filament/app/pages/campaigns.blade.php`, `app/app/Http/Controllers/MediaController.php`, route `GET /media/piece/{piece}` and `GET /media/upload/{upload}` (auth, signed URL redirect), `app/app/Support/Media.php`. Test: `tests/Feature/App/CampaignsPageTest.php`, `tests/Feature/App/MediaControllerTest.php`.

**Behavior:**
- Campaigns page (`$slug = ''` so it is the tenant home): Filament table over `Campaign::where('brand_id', Filament::getTenant()->id)`: `ImageColumn cover_path` (URL via `SignedUrlProvider` for disk `pieces`), name, description (limit 80), `series_count` (`withCount(['generations as series_count' => fn ($q) => $q->where('kind', 'series')->where('status', 'completed')])`), `pieces_count`, action **"Abrir"** → URL built as `"/app/{$tenant->slug}/campaigns/{$record->slug}"` (the Generator page in Task 21 registers exactly that slug; no class dependency here). Empty state: `"Todavía no hay campañas en esta marca."`
- `MediaController::piece(Piece $piece)`: authorizes `auth()->user()->brands()->whereKey($piece->campaign->brand_id)->exists()` (or Art Director), then `redirect()->away(SignedUrlProvider->url('pieces', $piece->storage_path, Media::ttl(), request()->boolean('download'), "pieza-{$piece->id}-{$piece->width}x{$piece->height}.{$ext}"))` with header `Cache-Control: no-store` so the browser never caches an expiring redirect. Same for `upload(InputUpload $u)` on disk `inputs`, `cover(Campaign $c)` and `logo(Brand $b)` on disk `pieces`. Route names `media.piece`, `media.upload`, `media.cover`, `media.logo`. **All image `src` in both panels use these routes**, never a stored URL. Every `<img>` rendered by the app panel gets `onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}"` so a URL that expired while a page sat open is re-requested once and re-signed.

- [ ] **Step 1: Failing tests**
```php
it('lists only the current brand campaigns with counts', function () {
    $ed = User::factory()->editor()->create(); $b = Brand::factory()->create(); $b->users()->attach($ed);
    $mine = Campaign::factory()->for($b)->create(['name' => 'Aliados']); Campaign::factory()->create(['name' => 'Ajena']);
    Livewire::actingAs($ed)->test(Campaigns::class, ['tenant' => $b])->assertCanSeeTableRecords([$mine])->assertSee('Aliados')->assertDontSee('Ajena');
});
it('redirects to a signed url only for pieces in the editor brands', function () {
    $ed = User::factory()->editor()->create(); $b = Brand::factory()->create(); $b->users()->attach($ed);
    $piece = Piece::factory()->create(); $piece->campaign->update(['brand_id' => $b->id]); $foreign = Piece::factory()->create();
    $this->actingAs($ed)->get(route('media.piece', $piece))->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($ed)->get(route('media.piece', $foreign))->assertForbidden();
    $b->update(['logo_path' => 'brands/l.png']);
    $this->actingAs($ed)->get(route('media.logo', $b))->assertRedirect();
});
```
Run → FAIL. **Step 2: Implement.** If `Livewire::test` for tenant pages needs the tenant set, call `Filament::setTenant($b)` in the test before rendering. **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: editor campaigns page and authorized media redirects"`

### Task 21: Generator page

**Files:** Create `app/app/Filament/App/Pages/Generator.php` + `resources/views/filament/app/pages/generator.blade.php`, `resources/views/filament/app/partials/piece-grid.blade.php`. Test: `tests/Feature/App/GeneratorPageTest.php`.

**Behavior:** route `/app/{brand}/campaigns/{campaign}` (`$slug = 'campaigns/{campaign}'`, resolve by slug within tenant, 404 otherwise).
- Header: breadcrumbs `Campañas / {name}`; heading = campaign name; subheading = active pipeline label; a small stats line `"{n} piezas en {m} series"` (`"sin piezas archivadas todavía"` when zero).
- Pipeline switcher: `Select` (Filament schema, statePath `pipelineId`) listing `activeGenerators()` when more than one; changing it resets `inputs` and rebuilds the form from `PipelineFormBuilder::components($pipeline)`. Default per spec. No active generator → `TextEntry` `"Esta campaña no tiene generador configurado."` and no submit.
- Form section titled `"1 · Qué quieres ver"` holding the built components; hidden `formRevision = pipeline->config_revision`. Primary action `"Generar serie"` → validates with `PipelineFormBuilder::rules`, finalizes any image temp paths via `InputUploadService::finalize` (replacing them with upload IDs; an already-finalized path from the previous submission is resolved with `InputUpload::where('storage_path')` and re-checked through `InputUploadService::authorize` for the current user), then `CreateGeneration::series(user, campaign, pipeline, inputs, requestId, formRevision)`; `requestId` is a UUID generated on mount and regenerated after each successful submit. On `ValidationException` show its message as a danger notification; on success notification `"Serie enviada. Aparecerá aquí cuando termine."` Inputs are **not** cleared.
- Right section `"Imágenes generadas"`: running generations (`nonTerminal()` for this campaign) as cards with `kind` label and `jobs.first.status` text (`"en cola"` when null; `"posición {n}"` when queue position present); "Última serie" = latest completed series with up to 4 thumbnails (`route('media.piece')`); latest failed generation card with `error_message` and, when `retryable`, a **"Comprobar estado"** action (`RestartGeneration::checkStatus`, shown for `poll_timeout`), a **"Reintentar descarga"** action (`checkStatus`, shown for `download_failed`), and a **"Generar de nuevo"** action that opens the restart modal (Task 22 supplies the shared modal component; here call `RestartGeneration::confirmRestart` inside a Filament `Action::make('restart')->requiresConfirmation()->modalHeading('¿Iniciar una nueva generación?')->modalDescription(...)->modalSubmitActionLabel('Sí, generar de nuevo')->modalCancelActionLabel('Cancelar')`, description text per spec §6.1 chosen by `failure_reason`); "Series anteriores" grid of up to 25 pieces with link `"Ver galería"`. Empty state `"Aquí saldrán tus imágenes."`
- `wire:poll.3s` on the results section only while `Generation::nonTerminal()->where('campaign_id', …)->exists()`.
- Thumbnails open the Viewer (Task 22) with `$dispatch('open-piece', { pieceId })`.

- [ ] **Step 1: Failing tests**
```php
function editorInCampaign(): array { $ed = User::factory()->editor()->create(); $b = Brand::factory()->create(); $b->users()->attach($ed); $c = Campaign::factory()->for($b)->create(); Filament::setTenant($b); return [$ed, $b, $c]; }

it('renders the schema form and submits a series with the current revision', function () {
    Queue::fake(); [$ed, $b, $c] = editorInCampaign(); $p = readyGenerator($c);
    Livewire::actingAs($ed)->test(Generator::class, ['campaign' => $c->slug])
        ->assertSee('Qué quieres ver')->fillForm(['inputs.describe_la_escena' => 'taller de bicis'])->call('generate')->assertHasNoFormErrors()->assertNotified('Serie enviada. Aparecerá aquí cuando termine.');
    expect(Generation::count())->toBe(1)->and(Generation::first()->execution_snapshot['inputs']['describe_la_escena'])->toBe('taller de bicis');
});
it('shows validation for a missing required field and never creates a generation', function () {
    [$ed, $b, $c] = editorInCampaign(); readyGenerator($c);
    Livewire::actingAs($ed)->test(Generator::class, ['campaign' => $c->slug])->call('generate')->assertHasFormErrors(['inputs.describe_la_escena']);
    expect(Generation::count())->toBe(0);
});
it('rejects a stale form revision with the spanish message', function () {
    [$ed, $b, $c] = editorInCampaign(); $p = readyGenerator($c);
    $lw = Livewire::actingAs($ed)->test(Generator::class, ['campaign' => $c->slug])->fillForm(['inputs.describe_la_escena' => 'x']);
    $p->increment('config_revision');
    $lw->call('generate')->assertNotified('La configuración cambió. Recarga el formulario.');
});
it('switches pipelines and shows the empty configuration message when none is active', function () {
    [$ed, $b, $c] = editorInCampaign();
    Livewire::actingAs($ed)->test(Generator::class, ['campaign' => $c->slug])->assertSee('Esta campaña no tiene generador configurado.');
    $a = readyGenerator($c); $b2 = readyGenerator($c); $b2->update(['label' => 'Segundo']);
    Livewire::actingAs($ed)->test(Generator::class, ['campaign' => $c->slug])->assertSee('Segundo')->set('pipelineId', $b2->id)->assertSet('formRevision', $b2->config_revision);
});
it('404s for a campaign of another brand', function () {
    [$ed, $b, $c] = editorInCampaign(); $other = Campaign::factory()->create();
    $this->actingAs($ed)->get("/app/{$b->slug}/campaigns/{$other->slug}")->assertNotFound();
});
```
Run → FAIL. **Step 2: Implement.** **Step 3: Run** → PASS. Manual check in browser with a seeded campaign and FakeEngine bound in a local `AppServiceProvider` `if (app()->environment('local') && env('FAKE_ENGINE'))` toggle so the full UI can be exercised without spend. **Step 4: Commit** — `git commit -m "feat: editor generator page with schema-driven form and results"`

### Task 22: Piece Viewer (edit, 4K, select, download) and Jobs bell

**Files:** Create `app/app/Livewire/PieceViewer.php` + `resources/views/livewire/piece-viewer.blade.php`, `app/app/Livewire/JobsBell.php` + `resources/views/livewire/jobs-bell.blade.php`. Register the viewer once in the app panel via `renderHook(PanelsRenderHook::BODY_END, fn () => Blade::render('@livewire(\'piece-viewer\')'))`. Test: `tests/Feature/App/PieceViewerTest.php`, `tests/Feature/App/JobsBellTest.php`.

**Behavior — PieceViewer** (Filament modal driven by `#[On('open-piece')] open(int $pieceId)`; authorizes the piece's brand against the tenant):
- Left: `<img src="{{ route('media.piece', $piece) }}">`, caption `"{width} × {height}"`, version strip = `$piece->versionChain()` thumbnails with `vNN` and a `4K` badge where `is_4k`; clicking switches `$piece`.
- The component mounts `$editRequestId` and `$upscaleRequestId` (UUIDs) and regenerates each only after its command succeeds, so a double-delivered click reuses the same `request_id` and `CreateGeneration` returns the existing Generation instead of paying twice.
- Right, "Editar la pieza": shows originating inputs from `generation.execution_snapshot['inputs']` with `labels` (images as thumbnails via `media.upload` / `media.piece`, text by label). Textarea `instruction` labelled `"Qué cambias"`, action **"Aplicar edición"** → `CreateGeneration::edit(user, piece, instruction, $this->editRequestId)`; disabled with text `"Esta campaña no tiene editor configurado."` when `campaign->activeEditor()` is null. Notification `"Edición enviada."`
- "Entrega": action **"Entregar en 4K"** `->requiresConfirmation()->modalHeading('Entregar en 4K')->modalDescription('Se generará una versión con 3.840 píxeles en el lado mayor, conservando la proporción.')` → `CreateGeneration::upscale(user, piece, $this->upscaleRequestId)`; disabled with `"Esta campaña no tiene upscaler configurado."` when none. When `FourKRule::meetsTarget($piece->width, $piece->height)` show `"Esta versión ya alcanza el tamaño de entrega."` and keep the action available.
- Toggle **"Marcar seleccionada"** with helper `"Visible para todo el equipo de la marca."` → `$piece->update(['selected' => !$piece->selected])`.
- **"Descargar"** → `redirect()->away(route('media.piece', [$piece, 'download' => 1]))` (opens the signed attachment URL fresh at click time).
- Running edit/upscale for this root: show `"Aplicando…"` line with `wire:poll.3s`; when a new version appears whose `parent_piece_id === $piece->id`, switch to it.
- Failed edit/upscale with `retryable`: **"Comprobar estado"** / **"Reintentar descarga"** (`RestartGeneration::checkStatus`) and **"Generar de nuevo"** restart action (same modal copy as Task 21).

Register the bell now: in `AppPanelProvider` add `->renderHook(PanelsRenderHook::TOPBAR_END, fn () => Blade::render('@livewire(\'jobs-bell\')'))`.

**Behavior — JobsBell** (in the topbar; `wire:poll.5s` plus an Alpine `x-data` with `@visibilitychange.document="$wire.$refresh()"` so a tab coming back to the foreground refreshes immediately; Livewire already throttles polling in background tabs, which satisfies the spec's intent):
- Query: `Generation::where('user_id', auth()->id())->whereHas('campaign', fn ($q) => $q->where('brand_id', Filament::getTenant()->id))->latest()->limit(30)`. Badge = count of terminal rows with `seen_at` null.
- Dropdown "Trabajos · Cola": per row kind label (`Serie`/`Edición`/`4K`), status label (`En cola`, `Procesando`, `Descargando`, `Lista`, `Falló`), prompt excerpt or `"{n} imágenes"` for image-only inputs, up to 3 thumbnails when completed, error message when failed, and for `retryable` failures the same **"Comprobar estado"** / **"Reintentar descarga"** / **"Generar de nuevo"** actions as the Generator (restart uses the shared confirmation modal copy). Opening the dropdown calls `markSeen()` = `UPDATE … SET seen_at = NOW() WHERE user_id = ? AND seen_at IS NULL AND status IN ('completed','failed')` for the displayed IDs only. Empty state `"Nada en la cola."` Footer `"Puedes seguir trabajando; te avisamos al terminar."`
- After `markSeen` or on poll, dispatch browser event `jobs-updated` so Generator/Gallery/Viewer refresh.

- [ ] **Step 1: Failing tests**
```php
it('opens a piece, applies an edit and refuses without an editor pipeline', function () {
    Queue::fake(); [$ed, $b, $c] = editorInCampaign(); $piece = Piece::factory()->create(); $piece->campaign->update(['brand_id' => $b->id]); $piece->refresh();
    $lw = Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertSee('Esta campaña no tiene editor configurado.');
    $editor = Pipeline::factory()->for($piece->campaign)->create(['kind' => 'editor', 'is_active' => true, 'readiness_errors' => [], 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($editor)->create(['name' => 'foto', 'input_type' => 'image', 'role' => 'image', 'required' => true]);
    PipelineField::factory()->for($editor)->create(['name' => 'q', 'input_type' => 'string', 'role' => 'prompt', 'required' => true]);
    Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->set('instruction', 'quita la caja')->call('applyEdit')->assertNotified('Edición enviada.');
    expect(Generation::where('kind', 'edit')->where('parent_piece_id', $piece->id)->exists())->toBeTrue();
});
it('disables 4K delivery without an upscaler and creates an upscale generation with one', function () {
    Queue::fake(); [$ed, $b, $c] = editorInCampaign(); $piece = Piece::factory()->create(['width' => 1920, 'height' => 1080]); $piece->campaign->update(['brand_id' => $b->id]);
    Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertSee('Esta campaña no tiene upscaler configurado.')->assertActionDisabled('upscale');
    $up = Pipeline::factory()->for($piece->campaign)->create(['kind' => 'upscaler', 'is_active' => true, 'readiness_errors' => [], 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($up)->create(['name' => 'image', 'input_type' => 'image', 'role' => 'image', 'required' => true]);
    $lw = Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id);
    $lw->callAction('upscale'); $lw->callAction('upscale'); // duplicate delivery reuses the request id
    expect(Generation::where('kind', 'upscale')->count())->toBe(1);
});

it('cancelling the restart modal creates nothing and confirming creates one linked generation', function () {
    Queue::fake(); fakeEngine(); [$ed, $b, $c] = editorInCampaign(); $piece = Piece::factory()->create(); $piece->campaign->update(['brand_id' => $b->id]);
    $editor = Pipeline::factory()->for($piece->campaign)->create(['kind' => 'editor', 'is_active' => true, 'readiness_errors' => []]);
    $failed = Generation::factory()->for($piece->campaign)->for($editor)->for($ed)->create(['kind' => 'edit', 'parent_piece_id' => $piece->id, 'status' => 'failed', 'failure_reason' => 'submission_unknown', 'retryable' => true]);
    $lw = Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertSee('Generar de nuevo');
    $lw->mountAction('restart', ['generation' => $failed->id])->unmountAction(); // open then cancel
    expect(Generation::count())->toBe(1);
    $lw->callAction('restart', ['generation' => $failed->id]);
    expect(Generation::where('restarted_from_generation_id', $failed->id)->count())->toBe(1);
});

it('toggles shared selection and refuses foreign pieces', function () {
    [$ed, $b, $c] = editorInCampaign(); $piece = Piece::factory()->create(); $piece->campaign->update(['brand_id' => $b->id]);
    Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->call('toggleSelected');
    expect($piece->fresh()->selected)->toBeTrue();
    $foreign = Piece::factory()->create();
    Livewire::actingAs($ed)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $foreign->id)->assertForbidden();
});
it('counts unseen terminal jobs and marks only displayed ones seen', function () {
    [$ed, $b, $c] = editorInCampaign(); $p = Pipeline::factory()->for($c)->create();
    Generation::factory()->for($c)->for($p)->for($ed)->create(['status' => 'completed']); Generation::factory()->for($c)->for($p)->for($ed)->create(['status' => 'processing']);
    $lw = Livewire::actingAs($ed)->test(JobsBell::class)->assertSet('unseen', 1)->call('markSeen')->assertSet('unseen', 0);
    expect(Generation::where('status', 'processing')->first()->seen_at)->toBeNull();
});
```
Run → FAIL. **Step 2: Implement.** **Step 3: Run** → PASS. **Step 4: Commit** — `git commit -m "feat: piece viewer with edit, 4K delivery, selection, download; jobs bell"`

### Task 23: Gallery page and Settings page

**Files:** Create `app/app/Filament/App/Pages/Gallery.php` + view, `Settings.php` + view. Test: `tests/Feature/App/GalleryPageTest.php`, `SettingsPageTest.php`.

**Behavior — Gallery** (`/app/{brand}/campaigns/{campaign}/gallery`): Filament table over `Piece::where('campaign_id', …)` with `ImageColumn` (via `media.piece`), `TextColumn` series/version label (`S{generation index}` for originals, `v{position in chain}` for edits/upscales), `"{w} × {h}"`, `IconColumn is_4k`, `IconColumn selected`. Filters (single-select `SelectFilter`): `Todas`, `Series` (kind original), `Ediciones` (kind edit), `4K` (is_4k), `Marcadas` (selected). Row action `"Ver"` dispatches `open-piece`; row action `"Descargar"` → `media.piece` with `download=1`. Header action `"Generar más"` → Generator URL. Empty state `"La galería está vacía. Genera una serie para empezar."` with action to the Generator.

**Behavior — Settings** (`/app/{brand}/ajustes`): form with `name`, `email` (unique ignoring self), `password` + `password_confirmation` (optional), action `"Guardar cambios"`; section `"Sesión"` with `"Cerrar sesión"` (`Filament::auth()->logout()`); two `TextEntry` items: `"Generaciones · próximamente"` and `"Equipo · próximamente"`.

- [ ] **Step 1: Failing tests**
```php
it('filters the gallery by kind, 4k and selection', function () {
    [$ed, $b, $c] = editorInCampaign(); $g = Generation::factory()->for($c)->create();
    $o = Piece::factory()->for($g)->for($c)->create(['kind' => 'original']); $e = Piece::factory()->for($g)->for($c)->create(['kind' => 'edit', 'selected' => true]); $u = Piece::factory()->for($g)->for($c)->create(['kind' => 'upscale', 'is_4k' => true]);
    $lw = Livewire::actingAs($ed)->test(Gallery::class, ['campaign' => $c->slug])->assertCanSeeTableRecords([$o, $e, $u]);
    $lw->filterTable('kind', 'edit')->assertCanSeeTableRecords([$e])->assertCanNotSeeTableRecords([$o, $u]);
    $lw->filterTable('kind', '4k')->assertCanSeeTableRecords([$u])->assertCanNotSeeTableRecords([$o, $e]);
    $lw->filterTable('kind', 'selected')->assertCanSeeTableRecords([$e]);
});
it('updates the profile and rejects a taken email', function () {
    [$ed] = editorInCampaign(); User::factory()->create(['email' => 'taken@x.com']);
    Livewire::actingAs($ed)->test(Settings::class)->fillForm(['name' => 'Ana R.', 'email' => 'taken@x.com'])->call('save')->assertHasFormErrors(['email']);
    Livewire::actingAs($ed)->test(Settings::class)->fillForm(['name' => 'Ana R.', 'email' => $ed->email])->call('save')->assertHasNoFormErrors();
    expect($ed->fresh()->name)->toBe('Ana R.');
});
```
Run → FAIL. **Step 2: Implement.** **Step 3: Run** whole suite → PASS. **Step 4: Commit** — `git commit -m "feat: gallery and settings pages"`

---

## Phase 8 — Evidence gates and integration

### Task 24: Production storage and queue wiring, storage integration checks

**Files:** Create `app/.env.production.example`, `docs/superpowers/plans/2026-09-XX-infra-checklist.md`; modify `app/README.md` (infra section).

- [ ] **Step 1: `.env.production.example`** — `QUEUE_CONNECTION=redis`, `MEDIA_URL_PROVIDER=cloudfront`, `CLOUDFRONT_DOMAIN`, `CLOUDFRONT_KEY_PAIR_ID`, `CLOUDFRONT_PRIVATE_KEY_PATH`, S3 bucket/region without endpoint overrides, `KREA_API_KEY` empty with a comment that brand keys override it.
- [ ] **Step 2: Infra checklist** (documentation, applied by whoever owns AWS): private bucket with public access blocked; CloudFront distribution with origin access control on the bucket; trusted key group holding the key pair whose ID is in `.env`; bucket CORS allowing `GET` from the app origin; CloudFront response headers policy passing `Content-Disposition`; lifecycle rule expiring `inputs/tmp/` after 1 day; IAM user for the app limited to `GetObject/PutObject/DeleteObject/ListBucket` on this bucket; Redis for queues. Each item has a one-line verification command or console check.
- [ ] **Step 3: Real MinIO storage checks** (dev, manual, recorded in the checklist): generate a 15 MiB PNG with GD, upload it through the Generator form, confirm the finalized object appears under `inputs/{brand}/` and the temporary object is gone, confirm the preview renders through `media.upload`, download a stored piece through `media.piece?download=1` and confirm the attachment filename. Any failure becomes a fix plus a regression test in the relevant task.
- [ ] **Step 4: Staging checks** (when staging exists): open a piece, wait 11 minutes, confirm the image reloads through the `onerror` re-request; download after expiry; revoke an Editor's brand and confirm `media.piece` returns 403.
- [ ] **Step 5: Commit** — `git commit -m "docs: production env example, infra checklist, storage integration checks"`

### Task 25: End-to-end dev run, queue and scheduler wiring, README

**Files:** Modify `app/routes/console.php` (schedule `queue:prune-failed`, and `media:clean-inputs` daily), create `app/app/Console/Commands/CleanUnreferencedInputs.php`, `app/README.md`.

- [ ] **Step 1: `media:clean-inputs`** — two-phase to avoid racing a concurrent `CreateGeneration`: pass 1 marks `input_uploads` older than 24 h with no `generation_inputs`/`pipeline_inputs` rows by setting `cleanup_marked_at = now()` (new nullable column, migration in this task); pass 2, on a later run, takes each row marked more than 10 minutes ago inside `DB::transaction` with `lockForUpdate()`, re-checks that it is still unreferenced, deletes the DB row **first** and the object second (an orphaned object is harmless; a dangling row pointing at a missing object is not). `CreateGeneration` clears `cleanup_marked_at` on any upload it links. Test in `tests/Feature/Media/CleanInputsTest.php`: referenced upload survives both passes, unreferenced is marked then deleted, an upload referenced between passes is unmarked and kept.
- [ ] **Step 2: README** — how to run: `docker compose up -d`, `php artisan migrate --seed`, `php artisan serve`, `php artisan queue:work --tries=1 --timeout=150`, `php artisan schedule:work` (runs `media:reconcile` every minute and `media:clean-inputs` hourly), `npm run dev`; env vars table; how to add a pipeline; note on rotating the prototype key; `FAKE_ENGINE=1` toggle for UI work without spend.
- [ ] **Step 3: Full manual walkthrough** with the real key (fixtures from Task 7b tell you what each pipeline expects): create brand with key → "Probar conexión" → campaign → 3 pipelines (generator, editor, upscaler) → refresh schema → configure fields → activate → login as editor → generate → wait → open viewer → edit → 4K → gallery filters → download. Fix anything found; add a regression test for each fix.
- [ ] **Step 4: Run** `./vendor/bin/pest` (all green) and `./vendor/bin/pint --test`. **Step 5: Commit** — `git commit -m "chore: scheduler, input cleanup, README, walkthrough fixes"`

---

## Self-review against the spec

- §2 roles/tenancy → Tasks 4, 17. §3 decisions → Global Constraints, Tasks 1–2, 7, 12. §4.1 engine → Tasks 6–8, evidence in 6b/7b. §4.2 sync/readiness → Tasks 9–10 (readiness first). §4.3 form → Task 11. §4.4 chain, edits, upscales, 4K, recovery/restart → Tasks 14–16, 16b, 21–22. §4.5 storage/uploads/delivery/retention → Tasks 2, 12, 13, 20, 24, 25. §5 data model → Tasks 4–5. §6.1 screens → Tasks 20–23; restart modal copy → Tasks 21–22. §6.2 admin → Tasks 18–19. §7 errors and logging → Tasks 7, 15–16. §8 security → Tasks 13, 17, 20. §9 testing → every task; storage integration → Task 24. §12 gates → Tasks 6b, 7b. Boost/Blueprint → Tasks 1, 18–19.
- Type consistency: `SubmissionOutcome` states `accepted/rejected/unknown` (Tasks 6, 7, 15); `JobObservation->normalizedStatus` (Tasks 6, 7, 16); snapshot keys `provider_ref`, `credential_source`, `inputs`, `labels`, `bindings`, `source_piece` (Tasks 15, 16, 22); input references `['__upload' => id]` / `['__piece' => id]` (Tasks 14, 15); `FourKRule::target/accepts/meetsTarget` (Tasks 14, 16, 22); route names `media.piece`, `media.upload` (Tasks 20–23).
- Deliberately not in this plan (slice 2+): ZIP export, 24 h reconciler, revision counters, host allowlist, custom theme.

---

## Revision log — 2026-09-05, after the Codex review

Decisions taken with the user: minimal reconcile command in slice 1; Gate A before engine work and Gate B right after Task 7; Art Director image fixed values in slice 1; plan revised by the planning session. Mapping of Codex findings (`2026-09-05-codex-plan-review.md`) to changes:

| Finding | Change |
| --- | --- |
| 1.1 redaction/logging | `KreaErrorMessages::sanitizeDetail`, `Log::withContext` in jobs (Tasks 7, 15) |
| 1.2 auto-deactivate | Sync and field saves deactivate invalid active pipelines (Tasks 10, 19) |
| 1.3 recovery controls | `checkStatus` re-arms polls and download retries; "Comprobar estado" / "Reintentar descarga" / "Generar de nuevo" in Generator, Viewer, and bell (Tasks 16, 21, 22) |
| 1.4 fixed images | Admin `FileUpload` for image fixed values, `pipeline_inputs` link, `authorizeForPipeline` (Tasks 13, 19) |
| 1.5 soft-deleted campaign | `CreateGeneration` rejects; workers use `withTrashed()`; test (Task 15) |
| 1.6 schema in snapshot | `schema` key added; `saving` guard makes the snapshot immutable (Task 15) |
| 1.7 production config, storage checks | New Task 24 |
| 1.8 URL renewal | `Cache-Control: no-store`, `onerror` re-request, cover/logo routes (Task 20) |
| 1.9 missing tests | Restart cancel, upscale disabled, duplicate upscale click (Task 22); root chain (Task 16) |
| 1.10 read-only field info | Name, required, source schema, needs_configuration columns (Task 19) |
| 1.11 brand logo | `brandLogo` via `media.logo` (Task 17) |
| 2.1 upload owner | `authorize(uploadId, brand, user)` (Task 13) |
| 2.2 refs vs validator | Scalars only through `validateValue`; refs validated by authorized existence (Tasks 9, 14) |
| 2.3 extra keys | `ValidationException("Campos no permitidos: …")`, test split (Task 14) |
| 2.4 keyword allowlist, pattern | `SUPPORTED_KEYS` enforced; `pattern` validated; readiness re-classifies `source_schema` (Tasks 9, 10) |
| 2.5 silent config changes | `needs_configuration` flag blocks readiness until an Art Director saves the field (Tasks 4, 10, 19) |
| 2.6 buffered download | Streamed read with Content-Length and running cap into `php://memory` (Task 8) |
| 2.7 decode, invalid_result | `imagecreatefromstring` decode; `InvalidImageException` → `invalid_result` (Tasks 8, 16) |
| 2.8 classify signature/count | Signature `(array $schema, string $name)`; counts fixed (Task 10) |
| 2.9 snapshot fixtures | `snapshot()` helper used by all fixtures and the factory (Tasks 7, 15, 16) |
| 2.10 concurrency test | Sequential test kept plus lock assertion; real race covered by walkthrough (Task 9) |
| 2.11 blank key | `use_studio_key` toggle (Task 18) |
| 2.12 required nullable | `required` whenever the field is required (Task 11) |
| 2.13 prompt rule | First non-image string by `sort_order` (Task 10) |
| 3.1 retry throws | `describe`/`inspect` wrap `ConnectionException` (Task 7) |
| 3.2 unit bootstrap | `Pest.php` applies TestCase to Unit and Feature (Task 1) |
| 3.3 path tampering | `allowFilePathUsing` with owner check (Task 11) |
| 3.4 boolean enum keys | Enum fields get only `in:` (Task 11) |
| 3.5 `value()` decrypts | Test uses `DB::table` (Task 4) |
| 3.6 connection fake | `Http::failedConnection()` with call counter (Task 7) |
| 3.7 `.visible` | `wire:poll.5s` + visibilitychange refresh; deviation noted (Task 22) |
| 3.8 Placeholder | `TextEntry` everywhere (Tasks 11, 21, 23) |
| 4.1 gate order | Task 6b (Gate A) and Task 7b (Gate B) before Task 8; prerequisites updated |
| 4.2 PollGenerationJob | Stub created in Task 15 |
| 4.3 hook/URL deps | Bell hook moved to Task 22; campaign "Abrir" builds the URL (Tasks 17, 20, 22) |
| 4.4 Task 9/10 order | Swapped: readiness (9) before sync (10) |
| 5.1 output identity | Unique `(generation_job_id, index)`; upsert by job (Tasks 5, 16) |
| 5.2, 5.3 stale claim, lost dispatch | New Task 16b `media:reconcile` |
| 5.4 inspect failures | Elapsed check first; 401/404 permanent (Task 16) |
| 5.5 partial recovery | Polling eligibility per child job (Task 16) |
| 5.6 download replay | Claim `pending/failed → downloading`; replay no-op (Task 16) |
| 5.7 restart eligibility | Eligibility, parent/upload re-authorization, partial-completion branch, recovery independent of pipeline activation (Task 16) |
| 5.8 edit/upscale request id | Mounted `editRequestId`/`upscaleRequestId` (Task 22) |
| 5.9 timeouts | Global Constraints: `retry_after 420`, job timeouts, worker `--timeout=150` (Tasks 2, 15, 16, 25) |
| 5.10 cleanup race | Two-phase mark-then-delete with lock and re-check (Task 25) |
| 5.11 lost revision | Sync works on the locked fresh instance (Task 10) |
| 5.12 download backoff | Three attempts, delays 5 s and 15 s; spec §4.4 wording aligned (Task 16) |
