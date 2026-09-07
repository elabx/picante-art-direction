# Muse Media Ops — Slice 1 design

**Date:** 2026-09-04 (converged 2026-09-05)
**Status:** converged after the [companion review](2026-09-04-muse-media-ops-slice-1-review.md) and the [Krea/Comfy research](../research/2026-09-04-engine-recommendation.md); pending the user's written approval before the implementation plan.
**Source prototypes:** `prototypes/Muse App.html` (full canvas), `prototypes/App Krea con flujos de usuarios/` (canvas source, design system, and the four earlier single-page flows: Inicio, Fotos Skechers, Generador Invierno, Historial). The folder is git-ignored because it contains a credential.

## 1. Purpose

Picante (the studio) fixes the art direction of a brand's imagery once as a Krea node app. Brand-side Editors then produce many pieces by filling a short form. This slice builds the Laravel/Filament product that replaces the browser-only prototype: a multi-brand workspace where Art Directors register Krea pipelines per campaign and Editors generate pieces, edit them into variations, upscale any of them to a 4K delivery version, and download, with every generated image copied into private storage the studio controls.

Product name in the UI: **Media Ops**, with the current brand's logo where Filament supports it. All UI text is Spanish. Slice 1 uses standard Filament layouts; the Modernist visual theme from the prototype is slice 2 (§11).

## 2. Roles and tenancy

- **Art Director** (studio staff). Uses the `/admin` panel. Sees every brand. Manages brands, users, campaigns, and pipelines (Krea node-app version IDs and their field configuration).
- **Editor** (brand staff). Uses the `/app` panel. Belongs to one or more brands via a pivot. Filament tenancy scopes everything in `/app` to the current brand; a tenant switcher appears when the Editor belongs to more than one brand.
- A single `users.role` column (`art_director` | `editor`) decides panel access. Slice 1 has no finer permissions.
- Sign-in is email and password on both panels using Filament's standard login page. Art Directors create Editor accounts and assign brands. Corporate SSO is out of scope.

## 3. Decisions taken

| Topic | Decision |
| --- | --- |
| Framework | PHP 8.5 (pinned by ddev), Laravel 12, Filament 5 (two panels), Livewire 4, Tailwind 4.1+, Pest. Filament 5 requires Livewire 4; lock a compatible set when scaffolding |
| Local environment | ddev is the only dev environment (decision 2026-09-07): project `muse` at the repo root, MySQL 8.0, MinIO and Redis add-ons, queue worker and scheduler as supervised daemons. No host PHP, Composer, Node, or MySQL |
| Dev-time AI tooling | Laravel Boost + Filament Blueprint (user holds a license) as dev dependencies; Blueprint's planning guidance refines each Filament resource task before it is implemented |
| Tenancy | By Brand in `/app`; none in `/admin` |
| UI | Standard Filament layouts, components, and styling in both panels. Brand logo and Media Ops naming only. Custom Modernist theme is slice 2 |
| Engine | Krea is the only implemented engine. A small `ImageEngine` interface with one `KreaEngine` implementation and provider-neutral column names keep a second engine (Comfy) possible without migrations. No staged-asset preparation, capability metadata, or automatic fallback in this slice |
| Generator form | Schema-driven from the Krea node app's `input_openapi_schema`, restricted to a declared subset, with per-field Art Director overrides and an editable semantic type |
| Campaign shape | Login → campaign list → Generator directly. No "addons" dashboard, no campaign status field or lifecycle. A campaign holds several switchable generator pipelines, at most one active editor, and at most one active upscaler |
| Variations | Edits and upscales are both variations in a piece's version chain. Upscales that meet the 4K contract carry a "4K" badge |
| 4K delivery | Exactly 3,840 px on the longest edge, aspect ratio preserved, integer rounding. Stored output dimensions are validated before the badge is shown |
| Editor controls | Generator shows its configured form. Editing asks only for an instruction on the viewed image. Upscaling uses the viewed image with no extra fields. Art Directors configure any other required values |
| Selection | "Marcar seleccionada" is shared curation for everyone with access to the campaign |
| Image storage | Object storage in every environment: AWS S3 in staging/production, the ddev MinIO add-on in dev. Every Krea result is downloaded into a private bucket; the Krea URL is kept as provenance |
| Image delivery | Private bucket behind CloudFront with origin access control; browsers get CloudFront signed URLs (10 min). Dev uses MinIO presigned URLs through the same interface |
| Recovery | Recover known jobs first; never re-execute automatically. A new potentially billable execution requires the Editor's explicit confirmation |
| Job completion | Queue worker polls Krea `GET /jobs/{id}`. Webhooks not used (unsigned) |
| Krea credentials | Per-brand encrypted key with studio-wide fallback in `.env`; source pinned per generation. Never exposed to the browser |
| Database / queue | MySQL 8.0; Redis queue in dev (ddev add-on) and production |

## 4. Architecture

```
Browser (Editor)  ── Livewire ──▶  /app panel (Filament, tenant = Brand)
Browser (AD)      ── Livewire ──▶  /admin panel (Filament)
                                        │
                                        ▼
                  Domain: PipelineSchemaSync · PipelineFormBuilder · GenerationRunner
                                        │
                                        ▼
                             ImageEngine (interface)
                                        │
                                   KreaEngine ── api.krea.ai
```

### 4.1 ImageEngine and KreaEngine

`ImageEngine` has four methods. `KreaEngine` is the only implementation; a `FakeEngine` is bound in tests.

- `describe(string $providerRef): EngineSchema` → `GET /node-apps/{versionId}`; returns the app name, `node_app_version_id`, and `input_openapi_schema`. A null schema is a readiness failure, not an empty form.
- `submit(string $providerRef, array $inputs): SubmissionOutcome` → `POST /node-apps/{versionId}/execute`; inputs keyed by schema property name; image inputs sent as `data:image/…;base64,` strings. Returns `accepted(jobIds[])`, `rejected(error)`, or `unknown(error)`. The response is normalised from a single object or an array into a non-empty list; every job ID is kept. No HTTP retries on this call.
- `inspect(string $jobId): JobObservation` → `GET /jobs/{id}`; returns normalised status (`pending`, `completed`, `failed`, `cancelled`), the native status string, queue position when present, the raw `result`, and `error`. Safe GETs may retry transient failures with bounded backoff.
- `outputs(array $result): OutputRef[]` → flattens `result.urls` (string, array, or object values) in deterministic order into `{index, url}` pairs, deduplicated by exact URL. Empty output is an error.

Timeouts: connect 5 s; 60 s for submit; 15 s for describe/inspect. `KreaException` carries the HTTP status and Krea's `message|error|detail`. Credentials resolve at dispatch from the pinned source (`brand` or `studio`); keys never enter snapshots, queue payloads, logs, or frontend state.

Result files are fetched by a separate `ResultDownloader` with no Authorization header: HTTPS only, no redirects, 64 MiB and 60 s cap, bytes decoded and validated as JPEG/PNG/WebP in memory before storage.

### 4.2 PipelineSchemaSync

Fetches the schema outside the transaction, then replaces `input_schema`, `schema_fetched_at`, and `pipeline_fields` together and increments `config_revision`. A failed fetch keeps the saved schema.

- **Supported subset:** a flat object whose properties are primitive `string`, `integer`, `number`, or `boolean`, optionally nullable, with primitive enums, defaults, string length/pattern, and numeric min/max. Nested arrays/objects, `$ref`, non-primitive unions, and other keywords are unsupported: readiness fails with the offending property and keyword shown to the Art Director.
- Each field stores `source_schema`, `required`, and an Art Director-editable `input_type` (`string`, `image`, `integer`, `number`, `boolean`, `unknown`). Image semantics apply to string transport. URI format or wording only *suggests* image; the Art Director confirms.
- Existing rows keep their overrides. Properties absent from the current schema become `stale = true` and are excluded from rendering, bindings, validation, and submitted values. **Removed required properties do not block readiness.** New required properties, unsupported shapes, or incompatible overrides block readiness until configured.
- On first sync, propose role `prompt` for the first non-image string and `image` for image-typed fields. Generators may have several image inputs or no prompt (Skechers).
- **Readiness** is computed from the current schema and current fields, stored in `readiness_errors`, and re-evaluated on every save. An active pipeline that becomes invalid is deactivated; accepted generations keep running from their snapshots.

### 4.3 PipelineFormBuilder

Turns a generator pipeline's non-stale, visible fields into Filament components:

| Field | Component |
| --- | --- |
| role `prompt` | Textarea, 4 rows, label "Qué quieres ver" unless overridden |
| `image` | FileUpload, images only, single file, preview, disk `inputs` |
| `string` | Textarea 2 rows |
| primitive enum | Select preserving JSON value types |
| `integer` / `number` | TextInput with integer / numeric validation |
| `boolean` | Toggle |
| `unknown` / unsupported | Admin configuration error; pipeline cannot activate |

Hidden fields are not rendered. Fixed values are JSON-typed; `has_fixed_value` distinguishes `false`, `0`, `""`, and `null` from "not configured". Required visible fields get `required()`; absent optional fields are omitted rather than sent empty. Extra client keys are rejected. The form carries the pipeline's `config_revision`; a submission with an outdated revision is rejected with "La configuración cambió. Recarga el formulario." before any provider call.

Editor and upscaler pipelines do not use this form. The Viewer supplies their automatic bindings (§4.4).

### 4.4 GenerationRunner

1. **`CreateGeneration`** authorizes the Editor's brand membership, the campaign, the pipeline (active, same campaign), any `input_uploads` by ownership record, and the parent piece for edits/upscales. It persists a `Generation` with `status = pending`, a unique client `request_id` (idempotent re-delivery of one form submit), and an immutable `execution_snapshot`: engine, provider ref, pipeline label and `config_revision`, supported schema, field labels and bindings, credential source, and fully composed typed inputs with images as upload or piece references. Links `generation_inputs`. Dispatches after commit.
2. **`RunGenerationJob`** atomically claims `pending → submitting` and records `submission_started_at`; only that transition authorizes one `submit`. It expands image references to data URLs, checks the aggregate request size against `media.max_request_bytes`, and calls the engine outside any transaction. `accepted` → writes all job IDs to `generation_jobs`, `status = submitted`, schedules polling. `rejected` → `failed`. `unknown` (timeout, connection loss) → `failed`, `failure_reason = submission_unknown`. A job re-delivered while the row is already `submitting` does not call the engine.
3. **`PollGenerationJob`** runs per `generation_jobs` row and keeps polling while **that child** is non-terminal, independent of the generation's aggregate status, delays 4 s, then 8 s after 2 min, then 15 s after 5 min. Transient inspect errors back off 15/30/60 s within the same 10 minute window; a 401 or 404 from the provider is permanent and fails that child immediately. A completed child writes its `generation_outputs` manifest before dispatching downloads. `failed`/`cancelled` children are recorded; other children continue. After 10 min without a terminal state the Generation becomes `failed`, `failure_reason = poll_timeout`, `retryable = true`; a manual "Comprobar estado" action re-inspects known jobs. A late completion found that way still flows through downloading to completed.
4. **`DownloadOutputJob`** runs per output: three attempts in total (retries after 5 s and 15 s), each attempt first claiming the output row so a replayed job is a no-op, stores under `pieces/{brand}/{campaign}/{generation}/{output_id}.{ext}`, validates bytes and dimensions, creates the Piece keyed by `generation_output_id` (replays are no-ops). Failed outputs keep their URL, attempt count, and error. The Generation is `completed` when every child job is terminal, at least one output exists, and every output is stored; a mix of stored and failed outputs is `failed` with the stored pieces still usable.

**Edits** use the campaign's active editor pipeline with `kind = edit` and `parent_piece_id`. Exactly one field with role `image` receives the parent piece's bytes; exactly one with role `prompt` receives the instruction. Bound fields cannot hold fixed values. Other required fields need Art Director-configured fixed values. Resulting pieces: `kind = edit`, `parent_piece_id` = the edited piece, `root_piece_id = parent.root_piece_id ?? parent.id`.

**Upscales** use the active upscaler with `kind = upscale`; exactly one `image` binding, no prompt binding, no Editor-facing fields. The Art Director configures the size setting the node app exposes; where the app takes explicit width/height, the runner computes them from the source (below) at submission. Resulting pieces: `kind = upscale`, same parent/root rule. An upscale is a variation and can itself be edited or downloaded.

**4K acceptance.** For source `W × H`, `s = 3840 / max(W, H)`; accepted stored size is `round(W·s) × round(H·s)`. 16:9 → 3840 × 2160; 9:16 → 2160 × 3840; square → 3840 × 3840. An upscaler output that misses this or changes aspect ratio is kept for inspection and download but the generation is `failed` with "La imagen recibida no cumple las dimensiones de entrega en 4K." and no badge. Pieces already at or above target can be downloaded directly; no automatic downsampling.

**Recovery and restart.** Recovery means re-inspecting known job IDs or retrying downloads; it never calls `submit`. When a generation is `failed` with `poll_timeout` or `submission_unknown`, the Editor sees "Generar de nuevo". The action first re-inspects known jobs; if they completed, their results are recovered and no new execution starts. Otherwise a Filament confirmation modal (§6.1) creates a *new* Generation from the original snapshot with `restarted_from_generation_id`, re-authorizing membership and source access. Cancelling creates nothing. Late results on the original stay accessible.

### 4.5 Storage and delivery

All images (uploads, pieces, campaign covers, brand logos) live in object storage in every environment; nothing image-related touches the web server's disk. Laravel `s3` driver, two private disks `inputs` and `pieces` as prefixes of one private bucket, configured only through `.env`. Dev uses the ddev MinIO add-on (`http://minio:10101` inside the web container; bucket `muse-media` created by a post-start hook).

**Uploads.** Livewire's temporary upload disk is set to the S3-backed `inputs` disk with rules `image|max:20480`; a bucket lifecycle rule expires temporary objects after 24 h. On acceptance the file is validated (JPEG/PNG/WebP bytes, MIME, dimensions, read in memory), copied to immutable `inputs/{brand}/{upload_uuid}.{ext}`, and an `InputUpload` ownership row is created. Filament's path-tampering protection plus an ownership-aware callback cover the custom form. Backend commands accept upload IDs, never client paths.

**Delivery.** `SignedUrlProvider::url(string $disk, string $path, DateTimeInterface $expiresAt, bool $download = false): string`. `CloudFrontSignedUrlProvider` (staging/production) signs with the AWS SDK `UrlSigner` from a key pair in `.env`; `PresignedS3UrlProvider` (dev) wraps `Storage::temporaryUrl()`. Chosen by `media.url_provider`. URLs are issued only after authorizing the owning record (Piece, InputUpload, cover, logo). Download actions request a fresh URL at click time; image components refresh URLs before expiry or after a failed load. Bucket and CloudFront CORS allow the app origins; the bucket is never public.

**Retention.** Pieces and referenced uploads are retained. Unreferenced finalized inputs are cleaned after 24 h. Soft-deleting a campaign hides it and blocks new commands but keeps assets and lets accepted work finish. Referenced pipelines, brands, and users cannot be hard-deleted.

## 5. Data model

All tables have `id`, `created_at`, `updated_at`; soft deletes where noted.

- **brands** — `name`, `slug` unique, `logo_path` nullable, `krea_api_key` encrypted nullable.
- **users** — Laravel defaults + `role` enum(`art_director`,`editor`).
- **brand_user** — `brand_id`, `user_id`, unique pair.
- **campaigns** — `brand_id`, `name`, `slug`, `description` nullable, `cover_path` nullable, `starts_on` / `ends_on` nullable (informational), `default_pipeline_id` nullable, soft deletes. No status.
- **pipelines** — `campaign_id`, `kind` enum(`generator`,`editor`,`upscaler`), `engine` string default `krea`, `provider_ref` string (Krea node-app version ID), `label`, `input_schema` json nullable, `schema_fetched_at` nullable, `config_revision` int default 1, `readiness_errors` json nullable, `sort_order` int, `is_active` bool. Activation runs under a transaction lock on the campaign and validates at most one active editor and one active upscaler. `default_pipeline_id` must be an active generator of the same campaign; deactivation clears an invalid default.
- **pipeline_fields** — `pipeline_id`, `name`, `source_schema` json, `input_type` enum(`string`,`image`,`integer`,`number`,`boolean`,`unknown`), `required` bool, `label_override` nullable, `help_text` nullable, `visibility` enum(`visible`,`hidden`), `has_fixed_value` bool default false, `fixed_value` json nullable, `role` enum(`prompt`,`image`,`none`), `stale` bool default false, `sort_order`. Unique (`pipeline_id`,`name`).
- **input_uploads** — `brand_id`, `user_id`, `storage_path` unique, `mime_type`, `bytes`, `width`, `height`, `finalized_at`. **generation_inputs** (`generation_id`,`input_upload_id`) unique and **pipeline_inputs** (`pipeline_id`,`input_upload_id`) unique keep referenced uploads retained.
- **generations** — `campaign_id`, `pipeline_id`, `user_id`, `kind` enum(`series`,`edit`,`upscale`), `parent_piece_id` nullable, `request_id` UUID unique, `execution_snapshot` json, `status` enum(`pending`,`submitting`,`submitted`,`processing`,`downloading`,`completed`,`failed`), `failure_reason` string nullable (`submission_unknown`, `poll_timeout`, `provider_failed`, `invalid_result`, `delivery_dimensions`, `download_failed`), `error_message` nullable, `retryable` bool default false, `restarted_from_generation_id` nullable, `submission_started_at` / `submitted_at` / `completed_at` nullable, `seen_at` nullable.
- **generation_jobs** — `generation_id`, `provider_job_id`, `status` string (native), `normalized_status` string, `queue_position` nullable, `result` json nullable, `error` json nullable, `last_polled_at` / `next_poll_at` nullable, `poll_failures` int default 0. Unique (`generation_id`,`provider_job_id`).
- **generation_outputs** — `generation_id`, `generation_job_id`, `index` int, `source_url` text, `status` enum(`pending`,`stored`,`failed`), `attempts` int default 0, `next_attempt_at` nullable, `error_message` nullable. Unique (`generation_id`,`index`).
- **pieces** — `generation_id`, `generation_output_id` unique, `campaign_id`, `kind` enum(`original`,`edit`,`upscale`), `parent_piece_id` nullable, `root_piece_id` nullable, `storage_path`, `source_url`, `width`, `height`, `bytes`, `mime_type`, `index` int, `is_4k` bool default false, `selected` bool default false (shared curation; each version independent).

Derived: a *series* is a `generations` row of kind `series` with its pieces. A piece's *version chain* is the root plus every piece whose `root_piece_id` equals the root's id, ordered by `created_at`, then `id`. `is_4k` is set only for kind `upscale` pieces that pass §4.4.

## 6. Screens

### 6.1 `/app` (Editor)

Standard Filament pages, sections, tables, actions, modals, and notifications. Exact layout fidelity to the prototype is not an acceptance criterion in this slice.

1. **Login.** Standard Filament login with Media Ops naming and brand logo.
2. **Campañas.** Table for the current brand: cover thumbnail, name, description, series/piece counts, "Abrir". Opens the Generator. All non-deleted campaigns are listed; creation stays in `/admin`.
3. **Generador** (`/app/{brand}/campaigns/{campaign}`), the campaign landing.
   - Breadcrumb `Campañas / {campaign}`, current pipeline label, series/piece counts.
   - More than one active generator: a Select switches pipeline and re-renders the form. Default is `default_pipeline_id`, else the first active generator by `sort_order`. None: "Esta campaña no tiene generador configurado." and generation disabled; pieces remain accessible.
   - Schema-driven form (§4.3) with primary action "Generar serie". Submit dispatches and clears nothing so another series can be queued.
   - "Imágenes generadas": empty state; running generations with Krea status text; "Última serie" (latest completed, up to 4 thumbs); latest failure with its Spanish message and, when applicable, "Generar de nuevo"; previous pieces with "Ver galería".
   - `wire:poll.3s` while any campaign generation is non-terminal.
4. **Galería** (`…/gallery`). Table with thumbnails, series/version labels, dimensions, shared selected state. Filters `Todas · Series · Ediciones · 4K · Marcadas`. Actions: open Viewer, "Generar más", per-row "Descargar". Empty state links to the Generator.
5. **Visor** (modal over Generator or Gallery). Image, actual dimensions, version thumbnails and list with "4K" badges, originating inputs as labelled fields (image-only inputs as thumbnails, multi-text inputs by field label). **Editar la pieza:** "Qué cambias" textarea and "Aplicar edición"; progress and error notifications. **Entrega:** "Entregar en 4K" with a confirmation stating the 3,840 px target. After a validated delivery the new version appears with its badge and the Viewer switches to it if still on the source. "Marcar seleccionada" (help text "Visible para todo el equipo de la marca."). "Descargar" downloads the shown version. Missing editor/upscaler: "Esta campaña no tiene editor configurado." / "…upscaler configurado." and the action disabled.
6. **Cola de trabajos.** A panel-level component on every `/app` page polls the Editor's generations in the current brand every 5 s while the tab is visible. The bell counts terminal generations with `seen_at` null. The drawer lists newest first with kind, status, input summary, and thumbnails; opening it marks displayed terminal items seen. Job updates also refresh a Viewer watching that source and the Generator's results.
7. **Ajustes.** Profile (name, email), password, "Cerrar sesión". Usage meter and team list are static "próximamente" placeholders.

**Restart confirmation** (Generator, Viewer, drawer). One modal for series, edits, and upscales. Title "¿Iniciar una nueva generación?". Body "El trabajo anterior podría seguir en curso. Iniciar una nueva generación puede generar un cargo adicional. Intentaremos recuperar el resultado anterior cuando sea posible." For a confirmed provider failure the first sentence becomes "El trabajo anterior terminó sin completarse." Confirm "Sí, generar de nuevo", cancel "Cancelar". Cancel, close, or Escape creates nothing; the action is disabled while processing. The server re-checks known jobs and authorization independently of the UI.

### 6.2 `/admin` (Art Director)

- **Brands** — CRUD; `krea_api_key` write-only password field; header action "Probar conexión" calls `GET /node-apps?limit=1` with the resolved key.
- **Users** — CRUD; role select; brands multi-select for editors.
- **Campaigns** — CRUD filtered by brand; relation managers **Pipelines** and read-only **Generations** (status, failure reason, user, pipeline, job IDs, timestamps) for support.
- **Pipelines** — create: kind, label, `provider_ref`, sort order. Saves inactive after a successful sync; a fetch failure blocks saving. Edit page: read-only name/source schema/required; editable `input_type`, label, help, visibility, typed fixed value, role. Header actions "Refrescar esquema" and "Activar". Readiness rules: generators need every visible or fixed required field valid; editors need exactly one `image` and one `prompt` binding; upscalers need exactly one `image` binding and no prompt; bound fields cannot hold fixed values; hidden required fields need a valid fixed value.

## 7. Error handling

Krea HTTP status → message: 400 "Solicitud inválida.", 401 "Clave de acceso inválida o faltante.", 402 "Saldo insuficiente.", 404 "No se encontró el flujo.", 429 "El servicio está saturado. Intenta en unos minutos.", 5xx "Error interno del servicio.", other "Error {status}.". Krea's detail is appended after redaction. Network failure → "No se pudo conectar con el servicio. Intenta de nuevo en unos segundos."

- Provider `failed`/`cancelled` → `failed`, `provider_failed`, `El proceso terminó con estado "{status}".`
- Poll timeout → `failed`, `poll_timeout`, `retryable`, "Se agotó el tiempo de espera. El trabajo podría seguir en curso."
- Unknown submission → `failed`, `submission_unknown`, "No pudimos confirmar si el trabajo se inició." Never resubmitted automatically.
- Empty or non-image output → `failed`, `invalid_result`, raw result kept on the child job.
- Failed downloads → `failed`, `download_failed`, stored pieces remain.
- Outdated form revision → rejected before any provider call with "La configuración cambió. Recarga el formulario."
- Invalid pipeline configuration → deactivated with actionable admin errors.
- Logs carry request ID, brand, pipeline, local and provider job IDs, status, and failure stage; never keys, data URLs, full input bodies, or signed URLs. Provider error bodies are redacted then truncated to 2 KiB.

## 8. Security

- Krea keys encrypted at rest, write-only in admin, resolved at dispatch from the pinned source.
- Every `/app` query and command starts from current brand membership; campaign → pipeline → parent-piece relationships are validated; a same-brand pipeline from another campaign is invalid. Workers use stored IDs and snapshots, never an ambient tenant.
- Uploads are accepted only via ownership records scoped to the uploading user (an Editor cannot use a colleague's upload); Art Director fixed images are linked to their pipeline. Signed URLs only after authorizing the owning record; 10 min expiry; bucket never public.
- Per-image limit 20 MiB and an aggregate request limit before submission (three images expand to ~80 MiB in base64).
- The prototype's hard-coded Krea key must be rotated; the new key is entered only in `/admin` or `.env`.

## 9. Testing

- **Unit:** `PipelineSchemaSync` (new/changed/removed properties, removed-required no longer blocks, default roles, override preservation, unsupported shapes), `PipelineFormBuilder` (each type, enum types preserved, hidden typed values incl. `false`/`0`, required, stale revision), status → message map, version-chain query with upscales, 4K rule for landscape/portrait/square and a failing case, `CloudFrontSignedUrlProvider` output shape.
- **Feature (Pest, `FakeEngine`, `Storage::fake`):** series → jobs → outputs → pieces; multi-job response keeps every job; edit sets kind/parent/root; upscale sets `is_4k` only when dimensions pass; submission claim blocks a second `submit` on redelivery; `unknown` outcome never resubmits; poll backoff and timeout; download retry with partial success; restart creates one linked generation and none when the original completed; Editor cannot open another brand's campaign, another campaign's pipeline, another user's upload, or `/admin`; Art Director CRUD; schema failure blocks save; one-editor/one-upscaler and default-pipeline invariants under concurrency (real MySQL).
- **Livewire:** Generator missing required field; valid submit dispatches; pipeline Select re-renders; Viewer edit/upscale disabled without pipelines; restart modal cancel creates nothing; bell count and seen marking.
- **Integration (Gate B, §12):** real MinIO upload of 12 to 20 MiB, preview, download; staging CloudFront expiry and renewal.

## 10. Out of scope for slice 1

Moved to slice 2 by decision on 2026-09-05: ZIP export of selected pieces (per-piece download covers delivery), 24-hour background recovery of unfinished provider jobs, notification/seen revision counters, result-host allowlist, Modernist theme. Slice 1 keeps a **minimal** `media:reconcile` command (every minute): stale `submitting` claims older than 120 s become `submission_unknown`, and `pending`/`downloading` rows or due polls whose queued job was lost are re-dispatched. It never calls the provider's execute endpoint. Later slices: media library / product picker, usage quota enforcement, team management and email notifications, corporate SSO, Krea webhooks, Comfy engine adapter, automatic downsampling of oversized originals.

## 11. Slice roadmap

1. **Slice 1 (this spec).** Core workspace, schema-driven generation, edits, 4K upscales, recovery with confirmation, private storage and signed delivery.
2. **Slice 2.** Modernist Filament theme (Archivo, zero radius, 2 px rules, red accent, poster login), ZIP export with persistence, background reconciler, bell revisions, host allowlist.
3. **Slice 3.** Media library / product picker with optional Krea `/assets` upload.
4. **Slice 4.** Usage quota (300 images/month per brand) meter and enforcement; team management in `/app`; notifications.
5. **Slice 5.** Comfy Developer Platform adapter per the research, if the studio chooses it.

## 12. Evidence gates before implementation

- **Gate A (no spend), before any engine code:** obtain from the studio the actual Krea node-app version IDs for the generator(s), editor, and candidate upscaler, fetch their schemas with the fresh key, and store sanitized copies as fixtures. Never reuse the prototype key.
- **Gate B (Krea smoke, ceiling US$10), immediately after the engine adapter exists and before the schema, form, and generation tasks:** run one generic text generation, one Skechers three-image execution, one Invierno execution, two edits, and three upscales (landscape, portrait, square); capture sanitized responses, job cardinality, output hosts, and dimensions. These become the test fixtures and confirm base64 transport and the 4K size binding; downstream tasks adapt field-name heuristics to the real schemas.
- Bootstrap installs Boost and Blueprint; Blueprint guidance is applied per resource task. Composer must be 2.8+ (currently 2.1.5); Docker daemon and dev/test MySQL databases verified at setup.
- Implementation is not declared ready from documentation alone; Gate B fixtures are required before the KreaEngine tasks are marked done.

## 13. Local environment notes

- **ddev** (v1.25+, Docker via OrbStack) is the development environment; verified 2026-09-07: PHP 8.5.7, Composer 2.10, Node 24, MySQL 8.0, MinIO, Redis. Config lives in `.ddev/config.yaml` (generated) and `.ddev/config.muse.yaml` (daemons and post-start hooks: `muse_test` database, `muse-media` bucket with a 1-day expiry on `inputs/tmp/`).
- Commands run from the repo root: `ddev composer …` and `ddev artisan …` execute inside `app/` (`composer_root: app`); `ddev pest`, `ddev pint`, `ddev npm …`, `ddev mysql`, `ddev mc …` (MinIO client), `ddev minio` (console), `ddev redis-cli`. Queue worker and scheduler restart with `ddev exec supervisorctl restart 'webextradaemons:*'`.
- URLs: app `https://muse.ddev.site`, MinIO console `https://muse.ddev.site:9090` (user and password `ddevminio`).
- Filament's package repository needs `ddev composer config --auth http-basic.packages.filamentphp.com <email> <license-key>` (lands in the git-ignored `app/auth.json`) before `ddev composer require filament/blueprint --dev`.
- The Laravel app lives in `app/` inside this repository; `docs/` stays at the root; `prototypes/` stays git-ignored.
