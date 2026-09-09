# Pipeline catalog — design

**Date:** 2026-09-09
**Status:** Tasks 1–9 implemented on 2026-09-09; Task 10 automated verification passed, browser acceptance and historical deletion decision pending. See [acceptance notes](../research/2026-09-09-catalog-acceptance.md).
**Builds on:** [Slice 1 design](2026-09-04-muse-media-ops-slice-1-design.md) §5 (pipelines) and §7 (data model).

## Approved UX update — 2026-09-09

The user removed the manual readiness step during implementation. Creating an app in the catalog validates its reference and fetches its schema automatically. Successful schema sync and field saves set `is_ready` from configuration validation; errors clear readiness and campaign defaults, and fixing the configuration restores availability automatically. The UI has no "Marcar lista" / "Marcar no lista" actions; its status label is "Disponible". The refresh action is labeled "Actualizar esquema". The seeder still never calls Krea and leaves new entries unavailable until their schema is fetched. These decisions supersede the manual readiness actions described below.

## 1. Purpose

Today a `Pipeline` belongs to one campaign. Registering the same Krea node app for five campaigns means typing the same version ID five times and configuring its fields five times; a Krea version change must be fixed in five places. This change turns pipelines into a **studio-wide catalog of apps** that campaigns reference. Field configuration (labels, visibility, fixed values, roles) lives only in the catalog. A campaign only chooses which catalog apps it uses and in what order.

Two deliverables:

1. The catalog model, admin UI, and campaign assignment.
2. A seeder that creates catalog entries from `.env` variables so local setups do not retype IDs.

## 2. Decisions

| Topic | Decision |
| --- | --- |
| Catalog scope | Studio-wide. One list for Picante; every brand's campaigns can use any entry |
| Field configuration | Only in the catalog. No per-campaign overrides of labels, visibility, fixed values, or roles |
| Assignment semantics | Assigning an app to a campaign is activation. No per-assignment active toggle. Removing the assignment is deactivation. Previously generated pieces stay visible |
| Readiness | Per catalog entry (`is_ready`). An entry that is not ready is hidden from Editors in every campaign until fixed |
| Migrations | Rewrite the original `pipelines` migrations so the schema is born with the catalog. Local databases are reset with `migrate:fresh`. No data migration |
| Schema sync credential | Catalog entries have no brand, so `PipelineSchemaSync` uses the studio key (`media.krea.key`). Generations keep resolving the key per brand and pinning `credential_source` in the snapshot, unchanged |
| Kind rule | Unchanged: several generators, at most one editor, at most one upscaler per campaign. Enforced at assignment |
| Seed IDs | Read from `.env` through `config/media.php`; the seeder never calls Krea |

## 3. Data model

### `pipelines` (now the catalog)

Rewrite `2026_09_07_154416_create_pipelines_table.php`:

- Remove `campaign_id`, `sort_order`, `is_active`.
- Add `is_ready` boolean default false.
- Keep `kind`, `engine` (default `krea`), `provider_ref`, `label`, `input_schema`, `schema_fetched_at`, `config_revision`, `readiness_errors`, timestamps.
- Unique index on (`engine`, `provider_ref`).

`pipeline_fields` and `pipeline_inputs` are unchanged and hang off the catalog entry.

### `campaign_pipeline` (new pivot)

New migration with a timestamp right after the pipelines migration:

- `campaign_id` FK → campaigns, cascade on delete.
- `pipeline_id` FK → pipelines, **restrict** on delete (an app in use cannot be deleted).
- `sort_order` unsigned integer default 0.
- Timestamps.
- Unique (`campaign_id`, `pipeline_id`).

### `campaigns.default_pipeline_id`

Unchanged column and migration (`2026_09_07_154421_add_default_pipeline_foreign_key_to_campaigns_table.php` already uses `nullOnDelete`). Rule: must point to a generator assigned to that campaign. Removing that assignment nulls the default.

### `input_uploads.brand_id`

Becomes nullable (rewrite `2026_09_07_155146_create_input_uploads_table.php`). Fixed images uploaded for a catalog field are stored under `catalog/<uuid>.<ext>` with `brand_id = null`. Editor uploads keep `brand_id` and the `<brand id>/<uuid>` path.

### Models

- `Pipeline::campaigns()` BelongsToMany with pivot `sort_order`. `Pipeline::isReady()` returns `is_ready`. Remove `campaign()`.
- `Campaign::pipelines()` BelongsToMany with pivot `sort_order`, ordered by `sort_order`, then `pipelines.id`.
- `Campaign::activeGenerators()`, `activeEditor()`, `activeUpscaler()` filter `kind` and `pipelines.is_ready = true` on that relation.
- `PipelineFactory`: no campaign (today it has no states, only `definition()`); add states `generator()`, `editor()`, `upscaler()` and `ready()` (sets `is_ready = true`, `readiness_errors = []`). The `readyGenerator(Campaign)` helper in `tests/Pest.php` creates the entry with `ready()` and attaches it to the campaign through the pivot.

## 4. Services

### `PipelineReadiness`

One change: the fixed-image check (`isLinkedImageUploadReference`) no longer compares the upload's brand with the campaign's brand, since catalog entries have no campaign. It only requires the upload to be linked through `pipeline_inputs`.

### `PipelineActivation` → readiness state

Keep the class name; replace its methods:

- `markReady(Pipeline)`: evaluate readiness, throw `ValidationException` with the errors if any, else set `is_ready = true` and `readiness_errors = []`.
- `markNotReady(Pipeline, array $errors)`: set `is_ready = false` and store errors. Called by `PipelineFieldConfiguration` and `PipelineSchemaSync` where they call `deactivate` today (a ready entry whose config changes and now has errors drops out of every campaign).
- `setDefault(Campaign, Pipeline)`: pipeline must be a generator, ready, and assigned to the campaign.
- Remove `activate`/`deactivate`.

### `CampaignPipelineAssignment` (new, `app/Services/Pipelines/`)

- `assign(Campaign, Pipeline, int $sortOrder)`: in a transaction with the campaign locked. Rejects if the entry is not ready, if already assigned, or if kind is editor/upscaler and the campaign already has an assigned entry of that kind (message unchanged: "Ya hay un {kind} activo en esta campaña."). Attaches with `sort_order`.
- `remove(Campaign, Pipeline)`: detaches; nulls `default_pipeline_id` if it pointed at this pipeline.
- `reorder(Campaign, Pipeline, int $sortOrder)`: updates pivot.

### `PipelineSchemaSync`

- Resolve the engine with the studio key: `EngineResolver::forSource` currently requires a `Brand`; add `EngineResolver::forStudio(): ImageEngine` that resolves `media.krea.key` (and honors the `FakeEngine` binding). Sync uses it.
- Drop the campaign/brand consistency checks inside the transaction; keep the `provider_ref` check and the pipeline lock.
- Where it deactivated a formerly active pipeline after a sync that produced errors, call `markNotReady`.

### `PipelineFieldConfiguration`

- Fixed image finalization calls a new `InputUploadService::finalizeForCatalog(string $temporaryPath, User $user): InputUpload` that stores under `catalog/` with `brand_id = null`.
- Drop the campaign/brand checks; keep the pipeline and field locks and the "configuration changed" guard.
- After saving, if `is_ready` and errors exist, `markNotReady`.

### `InputUploadService`

- `finalizeForCatalog` as above. Shared internals with `finalize`.
- `authorize(int, Brand, User)` unchanged for Editor uploads.
- `authorizeForPipeline(int, Pipeline)` unchanged.

### Media authorization (`MediaController`, `PieceViewer`)

Serving a fixed catalog image: the upload must be linked to the pipeline through `pipeline_inputs`, and the requesting user must be an Art Director or an Editor of a brand that has a campaign with that pipeline assigned. Replace the current `generation->pipeline?->campaign_id === piece->campaign_id` check with "the generation's pipeline is assigned to the piece's campaign, or the generation's snapshot references the upload".

### `CleanUnreferencedInputs`

Unchanged: uploads referenced by `pipeline_inputs` are already retained.

### `CreateGeneration`, `RestartGeneration`, `Generator` page

- Read pipelines through `campaign->pipelines()` filtered by kind and `is_ready`. `Generator::pipeline()` returns the entry if it is assigned to the campaign and ready, else null.
- Snapshot content is unchanged (`provider_ref`, `pipeline_label`, `config_revision`, fields, bindings). `generations.pipeline_id` now references a catalog entry.

## 5. Admin (`/admin`)

### Catalog resource ("Catálogo de apps")

Reuse `Filament/Admin/Resources/Pipelines`, promoted to a top-level navigation item.

- **List:** Nombre, Tipo (badge), Referencia del proveedor, Lista (boolean icon from `is_ready`), Campañas (count). Filter by tipo.
- **Create (modal or page):** Tipo, Nombre, Referencia del proveedor (same regex as today). On save: create, then sync schema with the studio key; a fetch failure rolls back and shows the error on `provider_ref` (same behavior as today's relation manager create).
- **Edit page:** current infolist (Nombre, Tipo, Referencia, Revisión, Errores) plus "Lista" state. Header actions: "Refrescar esquema", "Marcar lista" (calls `markReady`), "Marcar no lista". `FieldsRelationManager` unchanged except the upload path.
- **Delete:** allowed only when not assigned to any campaign (`PipelinePolicy::delete` returns true for Art Directors when `campaigns()->doesntExist()`; the DB restrict FK is the backstop).

### Campaign → pipelines relation manager ("Apps")

Replace `PipelinesRelationManager` create-form with assignment:

- Table columns: Orden, Nombre, Tipo, Referencia, Lista.
- Header action "Asignar app": Select of ready catalog entries not yet assigned (grouped or labeled by tipo), Orden. Calls `CampaignPipelineAssignment::assign`; validation errors surface on the select.
- Row actions: "Cambiar orden" (reorder), "Quitar" (remove, with confirmation), "Abrir en catálogo" (link to the edit page).
- No create, edit, refresh, activate, or field editing here.

### Campaign form

"Generador por defecto" select lists the campaign's assigned ready generators. `EditCampaign` validation uses `setDefault`.

## 6. Editor panel (`/app`)

No visible change. Campaign list, Generator select of generators, edit and 4K actions read the assigned ready entries. If an entry loses readiness, its generator disappears from the select and edit/4K buttons hide; the Generator page's existing "no generator" partial covers the empty case.

## 7. Seeder from `.env`

`config/media.php` gains:

```php
'krea' => [
    // existing keys…
    'test_apps' => [
        'generator' => env('KREA_TEST_APP_ID_GENERATOR'),
        'editor' => env('KREA_TEST_APP_ID_EDITOR'),
        'upscaler' => env('KREA_TEST_APP_ID_UPSCALER'),
        'skechers' => env('KREA_TEST_APP_ID_SKECHERS'),
        'invierno' => env('KREA_TEST_APP_ID_INVIERNO'),
    ],
],
```

`.env.example` lists the five variables empty. The user fills `.env` locally with the IDs from the prototype (generator `fbe97b3b-…`, editor and upscaler `1276c054-…`, Skechers `93a86f3d-…`, Invierno `c77d7e41-…`). Note that editor and upscaler share one ID today; the unique index on (`engine`, `provider_ref`) means the seeder creates **one** entry per distinct ID and keeps the first kind that claimed it. To seed both an editor and an upscaler entry the user needs two distinct IDs; until then the upscaler variable stays empty and the seeder logs a skip.

`PipelineCatalogSeeder` (called from `DatabaseSeeder`):

| Key | Kind | Label |
| --- | --- | --- |
| generator | generator | Creador Santander v3 |
| editor | editor | Editor Santander v2 |
| upscaler | upscaler | Upscaler Santander |
| skechers | generator | Fotos Skechers |
| invierno | generator | Generador Invierno |

For each key with a non-empty value: `updateOrCreate` by (`engine = krea`, `provider_ref`) setting `kind` and `label` only on create; leaves `input_schema`, fields, and `is_ready` untouched on existing rows. Skips empty values and duplicate IDs with a console line. Never calls Krea. Idempotent.

Local flow after seeding: open Catálogo de apps → Refrescar esquema (fake engine in `local` with the fake flag) → configure fields → Marcar lista → assign to a campaign.

## 8. Tests

Existing tests that build `Pipeline::factory()->for($campaign)` move to the factory without campaign plus assignment through the pivot (the shared helper in `tests/Pest.php` hides this for most files).

New or rewritten coverage:

- **Models:** relations and ready filters on `Campaign`; `Pipeline::campaigns()`.
- **Assignment service:** assigns a ready app; rejects not-ready, duplicate, and second editor/upscaler; remove nulls default; reorder.
- **Readiness state:** `markReady` success and failure; config change or resync with errors flips `is_ready` off and the app disappears from every campaign's active lists.
- **Schema sync:** uses the studio key; `FakeEngine` still bound in tests; provider_ref guard.
- **Catalog resource:** list, create with sync failure rollback, edit actions, delete blocked when assigned.
- **Campaign relation manager:** assign action with validation messages, remove, reorder; no create action rendered.
- **Campaign form:** default generator options and validation.
- **Generator page / PieceViewer / MediaController:** reads through the pivot; fixed catalog image served to an Editor of a brand whose campaign uses the app, denied to an Editor of another brand, allowed to Art Directors.
- **Uploads:** `finalizeForCatalog` stores under `catalog/` with null brand; cleanup retains referenced catalog uploads.
- **Seeder:** creates entries from config, skips empty and duplicate IDs, does not touch existing rows' schema or readiness, never hits the engine.

The full suite must pass and Pint must be clean before the branch is finished.

## 9. Out of scope

- Per-campaign field overrides (option B, rejected).
- Data migration for existing deployments (none exist).
- Calling Krea from the seeder or verifying the Skechers and Invierno IDs.
- Any change to generation, polling, storage, or 4K rules.
