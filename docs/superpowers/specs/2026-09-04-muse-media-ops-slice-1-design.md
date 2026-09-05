# Muse Media Ops — Slice 1 design

**Date:** 2026-09-04
**Status:** product decisions approved; engineering draft being reconciled. The [Krea/Comfy recommendation](../research/2026-09-04-engine-recommendation.md) now recommends Krea as the initial primary engine and Comfy as a deferred per-pipeline option. Its exact design amendments, missing workflow evidence, and proposed experiments must be resolved before engine-specific implementation planning is finalized. Krea-specific contracts below remain a candidate draft; the research recommendation is not user approval or live integration verification. Review status is tracked in the [companion review](2026-09-04-muse-media-ops-slice-1-review.md).
**Source prototypes:** `prototypes/Muse App.html` (full canvas), `prototypes/App Krea con flujos de usuarios/` (canvas source, design system, and the four earlier single-page flows: Inicio, Fotos Skechers, Generador Invierno, Historial)

## 1. Purpose

Picante (the studio) fixes the art direction of a brand's imagery once as a Krea node app. Brand-side Editors then produce many pieces by filling a short form. This slice builds the Laravel/Filament product that replaces the browser-only prototype: a multi-brand workspace where Art Directors register Krea pipelines per campaign and Editors generate pieces, edit them into variations, upscale any of them to a 4K delivery rendition, and download, with every generated image copied into storage the studio controls.

Product name in the UI: **Media Ops**, with the current brand's logo where supported by Filament. All UI text is Spanish. The prototypes guide the workflow; slice 1 uses standard Filament layouts and styling. Reproducing the Modernist visual design is deferred.

## 2. Roles and tenancy

- **Art Director** (studio staff). Uses the `/admin` panel. Sees every brand. Manages brands, users, campaigns, and pipelines (Krea node-app IDs and their field configuration).
- **Editor** (brand staff). Uses the `/app` panel. Belongs to one or more brands via a pivot. Filament tenancy scopes everything in `/app` to the current brand; a tenant switcher appears when the Editor belongs to more than one brand.
- A single `users.role` column (`art_director` | `editor`) decides panel access. Slice 1 has no finer permissions.
- Sign-in is email and password on both panels using Filament's standard login page. Art Directors create Editor accounts and assign brands. Corporate SSO is out of scope.

## 3. Decisions taken

| Topic | Decision |
| --- | --- |
| Framework | Laravel 12, Filament 5 (two panels), Livewire 4, Tailwind 4.1+, Pest |
| Dev-time AI tooling | Laravel Boost + Filament Blueprint (user holds a license) installed as dev dependencies during bootstrap; the installed Blueprint planning guidance refines the resource tasks before those tasks are implemented |
| Tenancy | By Brand in `/app`; none in `/admin` |
| UI | Standard Filament layouts, components, and styling in both panels. Basic brand logo and Media Ops naming only. Custom Modernist theme, poster login, and matching prototype layouts are deferred |
| Generator form | Schema-driven: rendered from the Krea node app's `input_openapi_schema`, with per-field Art Director overrides |
| Campaign shape | Login → campaign list → Generator directly. No "addons" dashboard. A campaign holds several switchable generator pipelines, at most one editor pipeline (variations), and at most one upscaler pipeline (4K delivery renditions) |
| Campaign lifecycle | Campaigns have no status field, status badges, or draft/review/archive workflow. All non-deleted campaigns are visible to Editors in their brand. Available actions depend on configured active pipelines; optional campaign dates are informational |
| Editor controls | Generator inputs follow its configured form. Editing asks only for an instruction for the currently viewed image; upscaling uses that image without extra parameter fields. Art Directors configure additional required values |
| Selection | `Marcar seleccionada` is shared curation for everyone with access to the campaign. Filament table checkboxes are temporary download selection and do not change shared marks |
| 4K delivery | Exactly 3,840 pixels on the longest edge, preserving the source aspect ratio with integer-pixel rounding. Validate stored output dimensions before showing a 4K badge |
| Image storage | All image handling goes through object storage in every environment: AWS S3 in staging/production, MinIO in local dev. Every Krea result is downloaded into a private bucket. Krea URL kept as reference |
| Image delivery | Private S3 bucket behind a CloudFront distribution; browsers receive CloudFront signed URLs (10 min). Dev uses S3/MinIO presigned URLs through the same interface |
| Job completion | Queue worker polls Krea `GET /jobs/{id}`. Webhooks not used (unsigned) |
| Recovery and restart | Recover the existing job first. Editors may explicitly start another generation through a standard Filament confirmation modal warning about a possible additional charge. A timeout never automatically starts another Krea execution |
| Krea credentials | Per-brand encrypted key with studio-wide fallback in `.env`. Never exposed to the browser |
| Database | MySQL |
| Queue | `database` driver in dev, Redis in production |
| Engine evaluation | Evaluate Comfy API as a secondary or primary engine before finalizing provider-specific models and jobs. Keep brand/campaign access, generation history, stored assets, shared selection, and exports independent of engine choice. The adapter interface and any automatic failover policy remain research outcomes |

## 4. Architecture

```
Browser (Editor)  ── Livewire ──▶  /app panel (Filament, tenant = Brand)
Browser (AD)      ── Livewire ──▶  /admin panel (Filament)
                                        │
                                        ▼
                                Domain services
                     ┌──────────────────┼───────────────────┐
                     ▼                  ▼                   ▼
              PipelineSchemaSync   GenerationRunner    PieceDownloader
                     │                  │                   │
                     └────────► KreaClient (HTTP) ◄─────────┘
                                        │
                                  api.krea.ai
```

### 4.1 KreaClient

Thin wrapper over Laravel's HTTP client, constructed with a bearer key. Methods:

- `getNodeApp(string $versionId): array` → `GET /node-apps/{id}`; returns name and `input_openapi_schema`.
- `execute(string $versionId, array $inputs): array` → `POST /node-apps/{id}/execute`; body is the inputs keyed by schema property name; image inputs are sent as `data:image/...;base64,` strings. Normalize a single object or array into a non-empty list of job objects and retain every returned job ID in `generation_jobs`; never select only the first result.
- `getJob(string $jobId): array` → `GET /jobs/{id}`.
- Result downloads use a separate `ResultDownloader`, with no Krea Authorization header. It accepts HTTPS URLs only from `media.result_hosts`, follows no redirects, and limits each response to 64 MiB and 60 seconds. Populate the host allowlist from verified Krea output fixtures during integration; unexpected hosts/redirects produce a recoverable download error. Decode and validate JPEG, PNG, or WebP bytes and dimensions in memory before storage; reject unsupported/non-image output.

Errors raise `KreaException` carrying the HTTP status and Krea's `message|error|detail`. A `KreaClientFactory` resolves the key: brand key if set, else `config('services.krea.key')`. An interface `KreaClientContract` allows a `FakeKreaClient` in tests.

Pin the resolved credential source (`brand` or `studio`) in the generation snapshot. Polling never silently switches source if a brand key is removed. Key rotation must retain access to the same Krea workspace; loss of access is a support error. Do not put keys in snapshots, queue payloads, logs, or frontend state. Use connect timeout 5 seconds and request timeout 60 seconds for execution; 15 seconds for metadata/status. Never configure automatic HTTP retries for execution POSTs. Safe GETs can retry transient failures with bounded backoff.

### 4.2 PipelineSchemaSync

Given a Pipeline, fetches the node app, stores `input_schema` and `schema_fetched_at`, and upserts `PipelineField` rows from `schema.properties`:

- Store each source property schema alongside its name and required flag. Supported input schemas are flat objects with primitive `string`, `integer`, `number`, or `boolean` properties, primitive enums, defaults, nullable primitive values, string lengths/patterns, and numeric minimum/maximum constraints. Reject nested arrays/objects, unresolved references, unions other than primitive-or-null, and unsupported validation keywords at readiness validation; show the offending property/keyword to the Art Director. This slice deliberately does not attempt a universal OpenAPI form renderer.
- `input_type` is an Art Director-editable semantic type (`string`, `image`, `integer`, `number`, `boolean`, `unknown`). Image semantics apply to string transport values. URI format or wording can suggest an image type but cannot silently activate it. Preserve compatible explicit overrides, and report incompatible overrides rather than coercing them.
- Properties absent from the current schema become `stale = true` and are excluded from rendering, bindings, validation, and submitted fixed values. Removed required properties do not block readiness. New required fields, unsupported shapes, or incompatible overrides block readiness until configured.
- On first sync, propose a prompt role for the first non-image string; Art Directors confirm bindings before activation. Generators can have multiple image inputs or no prompt (as in Skechers). Schema sync is atomic: fetch before the database transaction, then replace schema/fields together and increment `config_revision`. A failed fetch preserves the saved schema. Refresh and field edits re-evaluate readiness; invalid active pipelines are deactivated without affecting already accepted generations.

### 4.3 Form rendering

`PipelineFormBuilder` turns a Pipeline's non-stale, visible fields into Filament schema components:

| Field type / role | Component |
| --- | --- |
| role `prompt` | Standard Textarea, 4 rows, labelled "Qué quieres ver" unless overridden |
| `image` | FileUpload, images only, single file, preview, private disk `inputs` |
| `string` | Textarea 2 rows |
| primitive enum | Standard Select with values preserving their JSON types |
| `integer` | TextInput with integer validation |
| `number` | TextInput numeric |
| `boolean` | Toggle |
| `unknown` / unsupported schema | Configuration error in admin; pipeline cannot activate |

Hidden fields are not rendered. Fixed values are JSON-typed and distinguished from missing configuration by `has_fixed_value`; `false`, zero, empty strings, and explicit null are not mistaken for absent values. Validate fixed and visible values against the supported current schema. Required booleans may be false; nullable required values follow the source schema. Omit absent optional fields instead of sending empty strings. Extra client keys are rejected and fixed/automatic bindings are composed server-side. Labels use `label_override ?? name` humanised. A required hidden field without a valid fixed or automatic value prevents activation.

This configurable form is used for generator pipelines. Editor and upscaler pipelines use the restricted automatic bindings described in §4.4: the Viewer does not render additional pipeline fields. Art Directors supply additional required values in pipeline configuration; optional inputs may be omitted to use the node app's defaults.

### 4.4 GenerationRunner (queued job chain)

1. `CreateGeneration` authorizes the Editor, campaign, pipeline, and image references and locks the campaign/pipeline configuration while accepting the request. Persist `status = pending`, kind, and an immutable `execution_snapshot`: Krea version ID, pipeline label/revision, supported input schema, field labels/bindings, credential source, and fully composed typed inputs. Image inputs reference immutable `input_uploads` IDs or the source Piece ID; bytes are expanded to data URLs only in the worker. A UUID `request_id` makes repeated delivery of one form submission idempotent. Create `generation_inputs` links for upload retention and dispatch only after commit.
2. `RunGenerationJob` atomically claims `pending → submitting` and records `submission_started_at` before contacting Krea. Only that transition authorizes one execution attempt. The HTTP call is outside the transaction. Persist all returned job IDs together, set `submitted`, and schedule polling. Redelivery seeing `submitting` must not call Krea again. A confirmed rejection becomes `failed`; an ambiguous timeout/connection loss or abandoned claim becomes `failed/submission_unknown`. A one-minute reconciler marks a `submitting` claim older than 120 seconds ambiguous. This chooses a recoverable unknown outcome over risking duplicate paid work; it does not promise exactly-once remote execution.
3. `PollGenerationJob` handles one `generation_jobs` row, recording status/result/error and `next_poll_at`. Poll delays are 4 seconds, then 8 seconds after 2 minutes, then 15 seconds after 5 minutes, measured from submission. For transient GET errors (network, 429, 5xx), delay 15, 30, then 60 seconds, respecting a larger Retry-After within the remaining recovery window. A terminal completed job writes its output manifest before dispatching downloads. A failed/cancelled child does not stop recovery of other children. After 10 minutes, the Generation records a local `failed/poll_timeout` but known unfinished child jobs are checked every 60 seconds for up to 24 hours from submission. After that, stop automatic polling and retain a manual "Comprobar estado" action. Never submit another execution from polling or reconciliation.
4. `ResultParser` accepts `result.urls` as a string or nested arrays/object values of strings, flattens in deterministic order, validates allowed URLs, and deduplicates by exact URL within the Generation. Empty or unsupported output is `failed/invalid_result`; preserve the received result JSON on the child job for support. Upsert `generation_outputs` with stable index and SHA-256 URL identity before downloading. A `DownloadPieceJob` handles one output with three attempts (5/15/30-second delays); store under `pieces/{brand}/{campaign}/{generation}/{output_id}.{ext}`, validate bytes/dimensions, and create/upsert the Piece through its unique `generation_output_id`. Successful rows are skipped on replay. Failed files retain URL, attempts, and error, while successful files remain usable. Completion requires all child jobs completed, at least one output, and every output stored and delivery-valid. A mixed result becomes failed with accessible partial pieces after remaining children settle.

**Reconciliation and transitions.** Each job checks persisted state under a row lock and uses a per-record overlap lock; HTTP/object storage work occurs outside database transactions. A scheduled `media:reconcile` command runs every minute and redispatches pending submissions, due known child polls, or due output downloads if queue delivery was lost. It never clears a submission claim. Unexpected worker failure records the failed stage; known IDs/output rows can be resumed without execution. Queue `retry_after = 420` seconds, export worker timeout 300 seconds, ordinary job timeout 90 seconds, PHP memory limit 512 MiB. Local `failed/poll_timeout` may transition through downloading to completed when late results arrive; confirmed failed provider jobs cannot become successful without fresh provider evidence. Increment `notification_revision` on each newly reportable terminal outcome so a recovered completion can notify again.

Edits reuse the same chain with `kind = edit`, the campaign's editor pipeline, and `parent_piece_id` set. Exactly one field with role `image` receives the parent piece's bytes, and exactly one field with role `prompt` receives the Editor's instruction. These automatic bindings cannot have fixed values or be overridden by other input state. Other required fields must have valid Art Director-configured fixed values; optional unconfigured fields are omitted. Resulting pieces get `kind = edit`, `parent_piece_id` = the edited piece, and `root_piece_id = parent.root_piece_id ?? parent.id`.

Upscales reuse the chain with `kind = upscale`, the campaign's upscaler pipeline, `parent_piece_id` set, and the piece's bytes supplied to exactly one field with role `image`. The upscaler has no prompt binding and no additional Editor-facing parameter fields. Art Directors configure other required values and a size setting that targets the delivery dimensions below; optional unconfigured fields are omitted. A standard confirmation action starts the job. Resulting pieces get `kind = upscale`, `parent_piece_id` = the upscaled piece, and `root_piece_id = parent.root_piece_id ?? parent.id`. An upscale is a variation like an edit and can itself be edited or downloaded.

**4K acceptance.** For a source of `W × H`, let `s = 3840 / max(W, H)`. The accepted stored dimensions are `round(W × s) × round(H × s)`; cropping or stretching is not allowed. Examples: 16:9 → 3840 × 2160; 9:16 → 2160 × 3840; square → 3840 × 3840. Already conforming pieces can be downloaded directly. A larger source does not need a paid upscale solely to reach the smaller target; it can still be downloaded at its original dimensions, while automatic downsampling is deferred. An upscaler result that misses the target or changes aspect ratio is retained for inspection/download but the delivery generation fails with a Spanish explanation and receives no 4K badge. Successful provider execution alone does not establish delivery quality. The badge and Gallery's 4K filter identify only pieces of kind `upscale` whose stored dimensions pass this check. Edits remain kind `edit` and display their actual dimensions without inheriting the parent's badge.

**Recovering an existing job and starting another.** Recovery means checking/polling every known child Krea job or retrying result downloads; it never calls `execute`. After a timeout, attempt that recovery first whenever IDs exist. If Krea has completed, finish storing its results; if it is still running, offer continued monitoring. An Editor can still choose "Generar de nuevo" through the confirmation in §6.1. If no job ID was received, explain that the earlier submission could not be confirmed and require the same confirmation before creating another generation.

Immediately before accepting confirmation, re-check known original jobs. If all completed and their results can satisfy the original request, recover/show them instead of launching another execution; partial provider failures or invalid delivery output still allow a confirmed restart. `RestartGeneration` reauthorizes current brand membership and source access, then copies the original immutable execution snapshot into a separate Generation with `restarted_from_generation_id`. A deactivated/deleted pipeline or missing source input blocks restart with an explanation; editing inputs/settings is a normal new submission through the Generator. For edits/upscales retain the original `parent_piece_id`. Persist a server-issued unique `restart_request_id`, bound to actor, brand, and source Generation, so repeated confirmation delivery returns the same replacement. The modal is a UI for that authorized command, not a client-provided boolean. Cancelling/dismissing creates nothing. Preserve late outputs on the original record. `retryable` authorizes recovery of known work only, never automatic execution.

### 4.5 Storage

All images (Editor uploads, downloaded pieces, campaign covers, brand logos) live in object storage in every environment; no local-disk image handling. Laravel `s3` driver with two private disks, `inputs` and `pieces`, pointing at prefixes of one private bucket, configured only through `.env`. Local development runs MinIO in Docker (`docker compose up minio`).

**Uploads.** Configure Livewire's temporary upload disk explicitly to the S3-backed `inputs` disk and temporary rules to `file|max:20480`. Temporary uploads expire after 24 hours through a bucket lifecycle rule. Validate JPEG/PNG/WebP bytes, MIME type, and dimensions in memory using authorized SDK reads; do not make objects public to satisfy validation. On acceptance, copy to immutable `inputs/{brand}/{upload_uuid}.{ext}` and create an `InputUpload` ownership record. FileUpload path-tampering protection and an ownership-aware callback must cover this custom create form, including reuse of inputs from the preceding submission. Backend commands accept authorized Upload IDs, never arbitrary client paths. Use `php://memory` or bounded response streams, not `php://temp`, for code paths that might otherwise spill images to local disk. Storage fakes and local fixture images are permitted in tests.

**Delivery.** Staging/production use CloudFront OAC and signed URLs with a 10-minute expiry; local dev uses MinIO presigned URLs. `SignedUrlProvider::url(string $disk, string $path, DateTimeInterface $expiresAt, bool $download = false): string` maps the disk prefix to the origin key and signs the full URL, including any attachment response overrides. Choose the implementation via `media.url_provider`. The signing service is internal: authenticated media endpoints authorize a Piece, InputUpload, brand logo, campaign cover, or Export before resolving its path. Download actions request a fresh URL at click time; image components request fresh URLs before expiry or after an expired load, even when generation polling is inactive. Filament previews use the same provider through the disk's temporary-URL customization and FileUpload callbacks, including temporary previews. Configure bucket and CloudFront CORS for the app origins. The bucket denies public access but permits the app's authenticated reads/writes and presigned temporary uploads; OAC is the browser read path in production.

**Retention.** Generated pieces and uploads referenced by generations are retained. Clean unreferenced finalized inputs after 24 hours with a reference check under a lock. Campaign soft deletion hides it from Editors and prevents new commands but does not delete assets or prevent workers from finishing already accepted work. Referenced pipelines/brands/users cannot be hard-deleted in admin; deactivate pipelines or revoke brand membership instead. No automatic deletion of generated pieces is included.

### 4.6 Export service

`CreateExport` authorizes every selected Piece against the current campaign and snapshots ordered distinct IDs into `export_items`. Limits in `media` config: 50 files and 100 MiB combined source bytes per export. Refuse an empty/oversized request with Spanish validation. A UUID request token prevents duplicate export records. `BuildExportJob` reads private objects sequentially and uses ZipStream to write into a `php://memory` stream, capped at 112 MiB including archive overhead. Enforce the measured size during writing as well as metadata limits; never use a local temporary ZIP. Upload the completed ZIP to private `pieces/exports/{brand}/{export_uuid}.zip`, then mark completed and retain for 24 hours. Use stable names `pieza-{id}-{width}x{height}.{ext}`. Serialize work per Export; retries restart only the archive, never Krea. On any missing/read-failed file the export fails with no partial download, three total attempts, and a visible error. Retrying builds a new attempt for the same snapshot. An hourly cleanup expires/removes ZIP objects. The owner must still belong to the brand when requesting a fresh signed attachment URL; Art Directors can inspect export state for support. An expired export can be requested again from the Gallery.

## 5. Data model

All tables have `id`, `created_at`, `updated_at`; soft deletes where noted.

- **brands** — `name`, `slug` (unique), `logo_path` nullable, `krea_api_key` encrypted nullable, `monthly_image_cap` int default 300 (stored, not enforced in this slice).
- **users** — Laravel defaults + `role` enum(`art_director`,`editor`).
- **brand_user** — `brand_id`, `user_id`, unique pair.
- **campaigns** — `brand_id`, `name`, `slug`, `description` nullable, `cover_path` nullable, `starts_on` / `ends_on` nullable informational dates, `default_pipeline_id` nullable, soft deletes. No campaign status or lifecycle state machine.
- **pipelines** — `campaign_id`, `kind` enum(`generator`,`editor`,`upscaler`), `label`, `krea_version_id`, `input_schema` json nullable, `schema_fetched_at` nullable, `config_revision` integer default 1, `readiness_errors` json nullable, `sort_order` int, `is_active` bool. Activate/deactivate under a transaction lock on the campaign, then validate at most one active editor/upscaler. Validate a campaign default as one of its active generator pipelines; deactivation clears an invalid default and the UI falls back to the next active generator.
- **pipeline_fields** — `pipeline_id`, `name`, `source_schema` json, `input_type` enum(`string`,`image`,`integer`,`number`,`boolean`,`unknown`), `required` bool, `label_override` nullable, `help_text` nullable, `visibility` enum(`visible`,`hidden`), `has_fixed_value` bool default false, `fixed_value` json nullable, `role` enum(`prompt`,`image`,`none`), `stale` bool default false, `sort_order`. Unique (`pipeline_id`,`name`).
- **input_uploads** — `brand_id`, `user_id`, `storage_path` unique, `mime_type`, `bytes`, `width`, `height`, `finalized_at`. Objects are immutable. `generation_inputs` links (`generation_id`,`input_upload_id`) uniquely; Art Director fixed-image configuration uses `pipeline_inputs` links (`pipeline_id`,`input_upload_id`) so configured images are retained too.
- **generations** — `campaign_id`, `pipeline_id`, `user_id`, `kind` enum(`series`,`edit`,`upscale`), `parent_piece_id` nullable, `request_id` UUID unique, `inputs` json, `execution_snapshot` json, `status` enum(`pending`,`submitting`,`submitted`,`processing`,`downloading`,`completed`,`failed`), `error_message` nullable, `failure_reason` string nullable (including `poll_timeout` and `submission_unknown`), `retryable` bool default false, `restarted_from_generation_id` nullable self-reference, `restart_request_id` UUID nullable unique, `restart_confirmed_at` nullable, `submission_started_at` / `submitted_at` / `completed_at` nullable, `recovery_until` nullable, `notification_revision` integer default 0, `seen_revision` integer default 0, `seen_at` nullable. On a confirmed restart, `user_id` records the Editor who confirmed it and `restart_confirmed_at` is assigned by the server. The input snapshot includes uploads used as fixed values as well as visible inputs.
- **generation_jobs** — `generation_id`, `krea_job_id`, `status` string, `queue_position` nullable, `result` json nullable, `error` json nullable, `last_polled_at` / `next_poll_at` nullable, `poll_failures` integer default 0. Unique (`generation_id`,`krea_job_id`). Provider-specific names are provisional pending engine research.
- **generation_outputs** — `generation_id`, `generation_job_id`, `source_url` text, `url_hash` char(64), `index` integer, `status` enum(`pending`,`stored`,`failed`), `attempts` integer default 0, `next_attempt_at` nullable, `error_message` nullable, `delivery_valid` nullable bool. Unique (`generation_id`,`url_hash`) and (`generation_id`,`index`). Manifest rows survive failed downloads.
- **pieces** — `generation_id`, `generation_output_id` unique, `campaign_id`, `kind` enum(`original`,`edit`,`upscale`), `parent_piece_id` nullable, `root_piece_id` nullable, `storage_path`, `krea_url`, `width` / `height`, `bytes`, `mime_type`, `index` int, `selected` bool default false (shared campaign curation). Selection is independent for each version; new edits/upscales start unselected.
- **exports** — `campaign_id`, `user_id`, `request_id` UUID unique, `status` enum(`pending`,`processing`,`completed`,`failed`,`expired`), `storage_path` nullable, `bytes` nullable, `attempts` integer default 0, `error_message` nullable, `completed_at` / `expires_at` nullable. `export_items` contains `export_id`, `piece_id`, `sort_order`, unique (`export_id`,`piece_id`).

Derived: a *series* is a `generations` row of kind `series` with its pieces. A piece's *version chain* is the root piece plus every piece (kind `edit` or `upscale`) whose `root_piece_id` equals the root's id (the root itself has `root_piece_id` null), ordered by `created_at`, then `id`. Upscales that satisfy §4.4's delivery check are shown with a "4K" badge; all versions display their actual dimensions.

## 6. Screens

### 6.1 `/app` (Editor)

Use standard Filament pages, sections, forms, tables, actions, modals, and notifications. Add only the custom rendering needed to show generated images and their versions. Exact column placement, typography, decorative copy, and prototype screenshot matching are not acceptance criteria for this slice.

1. **Login.** Standard Filament login with Media Ops naming.
2. **Campañas.** Standard Filament table for the current brand: cover thumbnail, name, description, series/piece counts, and "Abrir" action. Clicking opens the Generator. No campaign status column or filters; all non-deleted campaigns in the brand are listed. Campaign creation remains in `/admin`.
3. **Generador** (`/app/{brand}/campaigns/{campaign}`), the campaign landing.
   - Standard page title and breadcrumb `Campañas / {campaign}`, current pipeline label, and campaign series/piece counts.
   - If more than one active generator: a standard Select switches pipeline and re-renders the form. The campaign's active generator `default_pipeline_id` is preselected, else the first active generator by `sort_order`. If none exists, show "Esta campaña no tiene generador configurado." and disable generation; existing pieces remain accessible.
   - Schema-driven form (§4.3) in a standard Section, with "Generar serie" as the primary action. Submit dispatches the chain and clears nothing, so the Editor can queue another series.
   - "Imágenes generadas" section: empty state; running generations with status indicators and Krea status text; "Última serie" (latest completed series, up to 4 thumbs); error notification for the latest failed generation; previous results with "Ver galería". Use standard tables/sections with thumbnail previews and a Viewer action.
   - Livewire `wire:poll.3s` refreshes results while campaign jobs are pending/submitting/submitted/processing/downloading. Panel job updates also refresh recovered results without requiring this page to poll indefinitely.
4. **Galería** (`/app/{brand}/campaigns/{campaign}/gallery`). Standard Filament table with image thumbnails, series/version labels, dimensions, and shared selected state. Standard filters: `Todas · Series · Ediciones · 4K` and `Marcadas`. Actions: open Viewer, "Generar más", and the "Descargar selección" bulk action. Table checkboxes select files for that export only; they do not change `pieces.selected`. Editors can filter to shared marks and select those rows for export. The queued export snapshots the checked piece IDs when submitted, so subsequent shared-mark or checkbox changes do not alter its contents. Empty state links back to the Generator.
5. **Visor** (standard Filament modal over Generator or Gallery). Show the image, actual dimensions, version thumbnails/list, and the originating inputs in labelled fields. The **Editar la pieza** section has only a "Qué cambias" textarea and "Aplicar edición" action; extra pipeline values are configured by the Art Director. Use standard progress indicators and error notifications. "Marcar seleccionada" updates shared campaign curation and includes help text "Visible para todo el equipo de la marca."; "Descargar" downloads the version currently shown. The **Entrega** section has "Entregar en 4K", with a standard confirmation stating the 3,840-pixel longest-edge target and no additional parameter fields. After a successful validated delivery, its version and 4K badge appear and the Viewer switches to it if the Editor is still viewing the source piece. Conforming pieces offer direct download; larger originals remain downloadable at their current dimensions. Missing editor/upscaler pipelines show "Esta campaña no tiene editor configurado." / "Esta campaña no tiene upscaler configurado." and disable the corresponding action. Quick prompt chips and cost estimates are deferred.
6. **Cola de trabajos.** A panel-level component polls the current Editor's jobs in the current brand every 5 seconds while the browser tab is visible, on every `/app` page. The bell counts reportable terminal outcomes with `notification_revision > seen_revision`. Drawer lists newest first with kind, status, input summary, details, and available thumbnails. Opening the drawer marks only the displayed terminal revisions as seen using a server-side conditional update; an unfinished item is not acknowledged. Late recovered completion increments the revision and becomes unseen again. Job updates refresh a Gallery Viewer watching that source and the Generator's results. Display image-only inputs as labelled thumbnails and multi-prompt inputs by their saved field labels, rather than inventing a single prompt. Exports have their own progress/status section in the drawer, refreshed by the same component.
7. **Ajustes.** Profile (name, email), password change, "Cerrar sesión". The usage meter and team list from the prototype are shown as static placeholders labelled "próximamente".

**Restart confirmation (Generator, Viewer, and job drawer).** The "Generar de nuevo" recovery action uses one standard Filament confirmation modal for series, edits, and upscales. First try to recover the existing job as described in §4.4. Modal title: "¿Iniciar una nueva generación?" Body: "El trabajo anterior podría seguir en curso. Iniciar una nueva generación puede generar un cargo adicional. Intentaremos recuperar el resultado anterior cuando sea posible." For a confirmed provider failure, replace the first sentence with "El trabajo anterior terminó sin completarse." Confirm button: "Sí, generar de nuevo". Cancel button: "Cancelar". Cancelling, closing, or pressing Escape starts no job; disable the confirmation action while its request is being processed. The server enforces authorization and confirmation deduplication independently of these UI controls.

### 6.2 `/admin` (Art Director)

Filament resources using standard Filament layouts and styling:

- **Brands** — CRUD; `krea_api_key` as a password field that is write-only; header action "Probar conexión" calls `GET /node-apps?limit=1` with the resolved key and reports success or the Krea error.
- **Users** — CRUD; role select; brands multi-select shown for editors.
- **Campaigns** — CRUD filtered by brand, with no campaign status field; relation manager **Pipelines**; read-only relation manager **Generations** (job status, user, pipeline, error, timestamps) for support.
- **Pipelines** (via relation manager and a standalone resource) — create form: kind, label, `krea_version_id`, sort order. New pipelines save inactive after a successful `PipelineSchemaSync`; a fetch failure blocks saving and shows the error. Edit page shows read-only name/source schema/required and editable semantic `input_type`, labels/help, visibility, typed fixed value, and role. Header actions "Refrescar esquema" and "Activar" allow configuration before activation. All saves increment the configuration revision; activation and default selection enforce §5's campaign-locked invariants. Editor pipelines require exactly one image binding and exactly one prompt binding; upscalers require exactly one image binding and no prompt binding. Bound fields cannot have fixed values. Additional required inputs need valid configured fixed values; optional unconfigured fields are omitted. Upscaler sizing targets §4.4. Generator pipelines retain their visible form. Invalid changes deactivate the pipeline while accepted execution snapshots remain runnable. Campaign statuses are not introduced.

## 7. Error handling

Krea HTTP status → Spanish message (from the prototype): 400 "Solicitud inválida.", 401 "Clave de acceso inválida o faltante.", 402 "Saldo insuficiente.", 404 "No se encontró el flujo.", 500 "Error interno del servicio.", other "Error {status}.". Krea's own detail is appended when present. Network failures → "No se pudo conectar con el servicio. Intenta de nuevo en unos segundos."

- Krea `failed`/`cancelled` → Generation `failed` with `El proceso terminó con estado "{status}".`
- Poll timeout (10 min) → local `failed`, `failure_reason = poll_timeout`, `retryable`, message "Se agotó el tiempo de espera. El trabajo podría seguir en curso." Recover the existing job first; submitting another requires §6.1's confirmation modal.
- Uncertain submission outcome → `failed`, `failure_reason = submission_unknown`, message "No pudimos confirmar si el trabajo se inició." Do not automatically repeat the submission. If its job ID becomes available, recover it; otherwise a new execution requires the same confirmation modal.
- Download failures: 3 attempts per file; if any file still fails the Generation is `failed` but pieces already stored remain, and Krea URLs are kept.
- An upscale whose stored output fails the target-dimension/aspect-ratio check is `failed` with "La imagen recibida no cumple las dimensiones de entrega en 4K." Its output remains accessible with actual dimensions and no 4K badge.
- Pipeline schema fetch failure blocks the pipeline save and shows the message inline.
- If current required inputs or semantic mappings are invalid, deactivate the pipeline and show an actionable admin configuration error. Removed stale fields do not block it. Submissions using an outdated form revision are rejected with "La configuración cambió. Recarga el formulario." before any provider execution.
- Log request ID, brand, pipeline, local/provider job IDs, status, and failure stage. Never log keys, image data URLs, complete input bodies, or signed image URLs. Redact sensitive response fields before truncating provider error details to 2 KiB; Editors see Spanish summaries, and Art Directors see sanitized support detail.

## 8. Security

- Krea keys are encrypted at rest (`encrypted` cast) and never rendered after save.
- Editors are scoped by tenancy; every `/app` query and mutating command starts from current membership. Check the campaign→pipeline→source-piece relationship, active generator/default eligibility, and every export item; a same-brand pipeline from another campaign is still invalid. Upload IDs resolve through authorized ownership records. `SignedUrlProvider` is called only after authorizing its owning record. Workers use stored brand/campaign IDs and snapshots, never an ambient Filament tenant. Membership is rechecked on Livewire updates, restarts, exports, and signed media requests. Revocation prevents new reads/commands; already accepted work may finish for the brand.
- Uploaded inputs are validated as images ≤ 20 MB and stored in a private bucket; nothing image-related is ever written to the web server's disk.
- CloudFront signed URLs (and dev presigned URLs) expire after 10 minutes. Production browser reads use OAC-protected CloudFront; app/service access and direct temporary uploads use narrowly authorized S3 requests. The bucket is never public.
- The prototype's hard-coded Krea key must be rotated by the user; the new key is entered only in `/admin` or `.env`.

## 9. Testing

- **Unit:** `PipelineSchemaSync` (new/changed/removed properties, default roles, override preservation), `PipelineFormBuilder` (each type, hidden merge, required), Krea status → message map, version-chain query including upscales, `CloudFrontSignedUrlProvider` produces a URL with `Expires`, `Signature`, and `Key-Pair-Id` for a given path.
- **Feature (Pest + `FakeKreaClient`):** full chain series generation creates pieces on `Storage::fake('pieces')`; edit generation sets `parent_piece_id`/`root_piece_id` and kind `edit`; upscale generation creates a kind `upscale` child that appears in the version chain with a 4K badge; poll backoff and timeout; download retry; Editor cannot open another brand's campaign or `/admin`; Art Director CRUD for every resource; pipeline save fails on schema error; one-editor-per-campaign validation.
- **Livewire:** Generator submit with a missing required field shows validation; Generator submit with valid data dispatches the chain; Viewer edit without editor pipeline is disabled; Viewer "Entregar en 4K" without upscaler is disabled.
- **Product acceptance:** landscape, portrait, and square upscale dimensions follow §4.4; below-target or distorted output receives no 4K badge; an edit does not inherit its parent's badge. Editing an original and editing an upscale both preserve the original root. Two Editors in the same brand see the same shared marks; changing a table's download checkboxes does not modify those marks, and an export retains its submitted piece IDs. Campaign listing and generation have no campaign-status gates. Editor/upscaler activation rejects missing automatic bindings or unconfigured required values. Standard Filament pages work at desktop and narrow widths; no custom-theme or prototype screenshot-match gate applies.
- **Recovery confirmation:** timeout and uncertain-submission paths never automatically call `execute` again. Recovering/polling/downloading an existing job does not require a paid-restart confirmation. Opening, cancelling, dismissing, or pressing Escape in the restart modal creates no Generation. Confirmation creates one linked replacement with the confirming Editor and timestamp; repeated delivery of the same confirmation creates no duplicate. If the original completes before confirmation is processed, recover its results and do not launch another execution through this recovery action. A confirmed edit/upscale restart keeps its original source piece, and any later recovered original results remain accessible.

## 10. Out of scope for slice 1 (later sub-projects)

2. Media library / product picker (brand-curated products, models, poses, backgrounds; optional upload to Krea `/assets`).
3. Usage quota enforcement and meter (300 images/month per brand).
4. Team management by Art Directors inside `/app`, in-app notifications beyond the bell, email digests, review links and "Enviar a revisión del estudio".
5. Corporate SSO.
6. Krea webhooks as a polling accelerator.
7. Custom Modernist theme, poster login, prototype layout fidelity, quick edit chips, and cost estimates. Standard Filament layouts are the initial UI.
8. Campaign status workflows and lifecycle gating; campaigns are simple containers for pipelines and pieces.
9. Automatic downsampling of originals larger than the 3,840-pixel delivery target; those originals remain downloadable at their actual dimensions.

## 11. Local environment notes

- Checked during planning: PHP 8.2.8, Composer 2.1.5, Node 22.14.0. Use Composer 2.8+ via a project-local or otherwise authorized install before scaffolding; do not overwrite system tools implicitly. Resolve packages against the actual PHP runtime and commit lockfiles.
- MySQL and Docker CLIs are installed. The sandbox could not access the Docker daemon at the OrbStack socket, so runtime availability is unverified. Use `php artisan serve`, a queue worker, and `php artisan schedule:work`, with MinIO from Compose or an authorized development bucket. Verify the daemon and dedicated development/test MySQL databases during bootstrap; do not use a production database for tests.
- Filament's package repository needs `composer config --auth http-basic.packages.filamentphp.com <email> <license-key>` before `composer require filament/blueprint --dev`.
- The Laravel app lives in `app/` inside this repository; `prototypes/` and `docs/` stay at the root.

## 12. Research and integration gates

- The documentation/prototype [Krea/Comfy comparison](../research/2026-09-04-engine-recommendation.md) is complete. It proposes a five-operation adapter, separate image preparation, native workflow/output mappings, stable output identities, and dynamic exact-4K bindings. Apply its amendment table after accepting engine scope; actual workflow access, large/private input transport, 4K output fixtures, and pricing remain unverified. The product decisions in §§1–3 remain the baseline.
- Capture sanitized real workflow/schema, execution, status, and output fixtures for the chosen provider(s). Verify multi-job cardinality, image upload transport, output hosts, custom model/node requirements, and 4K sizing. Published examples and fake-client tests are not evidence that a particular studio workflow works.
- Bootstrap installs Boost and licensed Blueprint, then invokes the installed guidance to refine Filament resource tasks. Blueprint has not been installed or invoked in this repository yet. Keep credentials out of chat, source control, and generated plans. Reference: [Filament AI tooling](https://filamentphp.com/docs/5.x/introduction/ai).
- Validate a real private MinIO upload between 12 and 20 MiB, an input preview, stored-result download, ZIP export, and cleanup. Staging verifies CloudFront expiry/renewal, CORS, attachment behavior, and access revocation. References: [Livewire uploads](https://livewire.laravel.com/docs/4.x/uploads), [Filament file upload](https://filamentphp.com/docs/5.x/forms/file-upload).
- Queue resilience uses MySQL/real workers for the submission-claim and activation concurrency checks. Unit/feature fakes cover most branches; do not claim integration success from them. Reference: [Laravel queues](https://laravel.com/docs/12.x/queues).
- The expanded engineering sections are a working draft. Reconcile their provider-specific fields and test coverage using the research amendment table after engine scope is accepted; the listed live evidence gates remain open and an implementation plan has not yet been completed.
