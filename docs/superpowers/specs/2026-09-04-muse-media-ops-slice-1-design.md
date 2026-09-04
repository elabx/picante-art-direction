# Muse Media Ops — Slice 1 design

**Date:** 2026-09-04
**Status:** approved in conversation, pending written review
**Source prototypes:** `prototypes/Muse App.html` (full canvas), `prototypes/App Krea con flujos de usuarios/` (canvas source, design system, and the four earlier single-page flows: Inicio, Fotos Skechers, Generador Invierno, Historial)

## 1. Purpose

Picante (the studio) fixes the art direction of a brand's imagery once as a Krea node app. Brand-side Editors then produce many pieces by filling a short form. This slice builds the Laravel/Filament product that replaces the browser-only prototype: a multi-brand workspace where Art Directors register Krea pipelines per campaign and Editors generate pieces, edit them into variations, upscale any of them to a 4K delivery rendition, and download, with every generated image copied into storage the studio controls.

Product name in the UI: **Media Ops** (brand logo + "Media Ops" wordmark as in the prototype). All UI text is Spanish.

## 2. Roles and tenancy

- **Art Director** (studio staff). Uses the `/admin` panel. Sees every brand. Manages brands, users, campaigns, and pipelines (Krea node-app IDs and their field configuration).
- **Editor** (brand staff). Uses the `/app` panel. Belongs to one or more brands via a pivot. Filament tenancy scopes everything in `/app` to the current brand; a tenant switcher appears when the Editor belongs to more than one brand.
- A single `users.role` column (`art_director` | `editor`) decides panel access. Slice 1 has no finer permissions.
- Sign-in is email and password on both panels (Filament's login page, restyled). Art Directors create Editor accounts and assign brands. Corporate SSO is out of scope.

## 3. Decisions taken

| Topic | Decision |
| --- | --- |
| Framework | Laravel 12, Filament 5 (two panels), Livewire 3, Tailwind 4, Pest |
| Dev-time AI tooling | Laravel Boost + Filament Blueprint (user holds a license) installed as dev dependencies; Blueprint's planning skill is used when specifying Filament resources |
| Tenancy | By Brand in `/app`; none in `/admin` |
| UI | Two Filament panels with one custom theme approximating the Modernist design system (Archivo, zero radius, 2px rules, red accent `#ec3013`, ground `#f3f2f2`, ink `#201e1d`) |
| Generator form | Schema-driven: rendered from the Krea node app's `input_openapi_schema`, with per-field Art Director overrides |
| Campaign shape | Login → campaign list → Generator directly. No "addons" dashboard. A campaign holds several switchable generator pipelines, at most one editor pipeline (variations), and at most one upscaler pipeline (4K delivery renditions) |
| Image storage | All image handling goes through object storage in every environment: AWS S3 in staging/production, MinIO in local dev. Every Krea result is downloaded into a private bucket. Krea URL kept as reference |
| Image delivery | Private S3 bucket behind a CloudFront distribution; browsers receive CloudFront signed URLs (10 min). Dev uses S3/MinIO presigned URLs through the same interface |
| Job completion | Queue worker polls Krea `GET /jobs/{id}`. Webhooks not used (unsigned) |
| Krea credentials | Per-brand encrypted key with studio-wide fallback in `.env`. Never exposed to the browser |
| Database | MySQL |
| Queue | `database` driver in dev, Redis in production |

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
- `execute(string $versionId, array $inputs): array` → `POST /node-apps/{id}/execute`; body is the inputs keyed by schema property name; image inputs are sent as `data:image/...;base64,` strings; returns the first job object (the API returns an array).
- `getJob(string $jobId): array` → `GET /jobs/{id}`.
- `download(string $url): string` → raw bytes of a result file.

Errors raise `KreaException` carrying the HTTP status and Krea's `message|error|detail`. A `KreaClientFactory` resolves the key: brand key if set, else `config('services.krea.key')`. An interface `KreaClientContract` allows a `FakeKreaClient` in tests.

### 4.2 PipelineSchemaSync

Given a Pipeline, fetches the node app, stores `input_schema` and `schema_fetched_at`, and upserts `PipelineField` rows from `schema.properties`:

- `name` = property key, `type` derived from the schema (`image` when `format` is `uri`/`binary` or the property name/description mentions image, else `string`, `number`, `boolean`, `unknown`), `required` from `schema.required`.
- Existing rows keep their overrides. Properties no longer in the schema are marked `stale = true` and excluded from rendering.
- On first sync, heuristics set defaults: the first required string property becomes role `prompt`; image properties get role `image`.

### 4.3 Form rendering

`PipelineFormBuilder` turns a Pipeline's non-stale, visible fields into Filament schema components:

| Field type / role | Component |
| --- | --- |
| role `prompt` | Textarea, 4 rows, rendered as step 1 with the "Qué quieres ver" header |
| `image` | FileUpload, images only, single file, preview, private disk `inputs` |
| `string` | Textarea 2 rows |
| `number` | TextInput numeric |
| `boolean` | Toggle |
| `unknown` | TextInput with help text "campo sin tipo conocido" |

Hidden fields are not rendered; on submit their `fixed_value` is merged into the inputs. Required visible fields get `required()`. Labels use `label_override ?? name` humanised.

### 4.4 GenerationRunner (queued job chain)

1. Editor submits → `Generation` created with `status = pending`, inputs JSON (uploaded images stored on disk `inputs`, JSON holds their paths), `kind = series` or `edit`.
2. `RunGenerationJob`: reads images from disk into data URLs, merges fixed values, calls `execute`, stores `krea_job_id`, `status = submitted`. On `KreaException` → `status = failed`, `error_message` from the status map in §7.
3. `PollGenerationJob`: calls `getJob`. Non-terminal → stores Krea status text and re-dispatches itself with delay 4 s (8 s after 2 minutes, 15 s after 5 minutes); marks `status = processing`. Terminal `completed` → `status = downloading`, dispatches `DownloadPiecesJob`. Terminal `failed` / `cancelled` → `status = failed`. Older than 10 minutes → `status = failed`, `error_message = "Tiempo de espera agotado."`, `retryable = true`.
4. `DownloadPiecesJob`: for each URL in `result.urls` (array or object values, deduplicated), downloads bytes, stores under `pieces/{brand}/{campaign}/{generation}/{index}.{ext}`, reads dimensions, creates `Piece`. Three tries per file. Then `status = completed`. Krea URL is kept on the Piece regardless.

Edits reuse the same chain with `kind = edit`, the campaign's editor pipeline, `parent_piece_id` set, and the parent piece's bytes supplied to the pipeline's field with role `image`, the instruction to the field with role `prompt`. Resulting pieces get `kind = edit`, `parent_piece_id` = the edited piece, `root_piece_id` = the edited piece's root.

Upscales reuse the chain with `kind = upscale`, the campaign's upscaler pipeline, `parent_piece_id` set, and the piece's bytes supplied to the field with role `image`. The upscaler has no prompt; any other visible fields are shown in a small confirm dialog before running. Resulting pieces get `kind = upscale`, `parent_piece_id` = the upscaled piece, same `root_piece_id`. An upscale is a variation like an edit: it appears in the piece's version strip and list, marked with a "4K" badge, and it can itself be edited or downloaded.

### 4.5 Storage

All images (Editor uploads, downloaded pieces, campaign covers, brand logos) live in object storage in every environment; no local-disk image handling. Laravel `s3` driver with two private disks, `inputs` and `pieces`, pointing at prefixes of one private bucket, configured only through `.env`. Local development runs MinIO in Docker (`docker compose up minio`).

**Delivery.** In staging and production the bucket sits behind a CloudFront distribution with an origin access control, and every image URL handed to the browser is a **CloudFront signed URL** (canned policy, 10 minute expiry) produced with the AWS SDK's `UrlSigner` from a CloudFront key pair whose ID and private key live in `.env`. A `SignedUrlProvider` interface has two implementations: `CloudFrontSignedUrlProvider` (staging/production) and `PresignedS3UrlProvider` (dev, wraps `Storage::temporaryUrl()` against MinIO). The implementation is chosen by `config('media.url_provider')`. Filament `FileUpload` writes straight to the `inputs` disk and previews through the same provider. Uploaded inputs are read back from object storage into base64 data URLs when sent to Krea, as in the prototype; switching to presigned URLs as Krea inputs is a later optimisation once confirmed against the schema.

## 5. Data model

All tables have `id`, `created_at`, `updated_at`; soft deletes where noted.

- **brands** — `name`, `slug` (unique), `logo_path` nullable, `krea_api_key` encrypted nullable, `monthly_image_cap` int default 300 (stored, not enforced in this slice).
- **users** — Laravel defaults + `role` enum(`art_director`,`editor`).
- **brand_user** — `brand_id`, `user_id`, unique pair.
- **campaigns** — `brand_id`, `name`, `slug`, `description` nullable, `status` enum(`draft`,`in_production`,`in_review`,`archived`), `cover_path` nullable, `starts_on` / `ends_on` nullable dates, `default_pipeline_id` nullable, soft deletes.
- **pipelines** — `campaign_id`, `kind` enum(`generator`,`editor`,`upscaler`), `label`, `krea_version_id`, `input_schema` json nullable, `schema_fetched_at` nullable, `sort_order` int, `is_active` bool. Constraint: at most one active `editor` and at most one active `upscaler` per campaign (enforced in validation).
- **pipeline_fields** — `pipeline_id`, `name`, `type` enum(`string`,`image`,`number`,`boolean`,`unknown`), `required` bool, `label_override` nullable, `help_text` nullable, `visibility` enum(`visible`,`hidden`), `fixed_value` text nullable, `role` enum(`prompt`,`image`,`none`), `stale` bool default false, `sort_order`. Unique (`pipeline_id`,`name`).
- **generations** — `campaign_id`, `pipeline_id`, `user_id`, `kind` enum(`series`,`edit`,`upscale`), `parent_piece_id` nullable, `inputs` json, `krea_job_id` nullable, `status` enum(`pending`,`submitted`,`processing`,`downloading`,`completed`,`failed`), `krea_status` string nullable, `queue_position` int nullable, `error_message` nullable, `retryable` bool default false, `seen_at` nullable, `submitted_at` / `completed_at` nullable.
- **pieces** — `generation_id`, `campaign_id`, `kind` enum(`original`,`edit`,`upscale`), `parent_piece_id` nullable, `root_piece_id` nullable, `storage_path`, `krea_url`, `width` / `height` nullable, `bytes` nullable, `index` int, `selected` bool default false.

Derived: a *series* is a `generations` row of kind `series` with its pieces. A piece's *version chain* is the root piece plus every piece (kind `edit` or `upscale`) whose `root_piece_id` equals the root's id (the root itself has `root_piece_id` null), ordered by `created_at`. Upscales are shown in the chain with a "4K" badge.

## 6. Screens

### 6.1 `/app` (Editor)

1. **Login.** Filament login, themed. Right half carries the red poster statement from the prototype.
2. **Campañas.** Card grid for the current brand: cover, status tag, name, description, `N series · M piezas`, "Abrir →". Clicking opens the Generator for that campaign. No "new campaign" here; campaigns are created by Art Directors.
3. **Generador** (`/app/{brand}/campaigns/{campaign}`), the campaign landing.
   - Breadcrumb `Campañas / {campaign}`. Header: pipeline label, campaign name, helper copy, and a "Esta serie" counter block showing pieces and series counts.
   - If more than one active generator: a tab row to switch pipeline; the form re-renders. The campaign's `default_pipeline_id` is preselected, else the first by `sort_order`.
   - Left column: the schema-driven form (§4.3), a footer line with the library summary, and the primary button "Generar serie →". Submit dispatches the chain and clears nothing, so the Editor can queue another series.
   - Right column "Imágenes generadas": empty state; running generations as placeholder tiles with the Krea status text; "Última serie" (latest completed series, up to 4 thumbs); error block for the latest failed generation; "Series anteriores" (up to 25 thumbs) with "Ver galería".
   - Livewire `wire:poll.3s` is active only while any generation for this campaign is non-terminal.
4. **Galería** (`/app/{brand}/campaigns/{campaign}/gallery`). Filter chips `Todas · Series · Ediciones · 4K`; grid of pieces with badge (`S01`, `v02`) and caption; side card with "Ficha del proyecto" (pipeline label, latest prompt), "Series" rows, and actions "Generar más" and "Descargar selección" (zips selected pieces via a queued export and a download link). Empty state links back to the Generator.
5. **Visor** (modal over Generator or Gallery). Left: the piece, version thumbnails, `vNN` label. Right: kicker + "Editar la pieza", the originating prompt, "Qué cambias" textarea with quick chips (Cambiar fondo, Quitar objeto, Ajustar luz, Ampliar encuadre) that append text, "Aplicar edición →" and cost line, progress block while the edit runs, error block, "Versiones de esta pieza" list, "Marcar seleccionada" toggle, "Descargar". Below: an **Entrega** block with "Entregar en 4K →" that runs the upscaler on the piece currently shown; while running it shows progress; when done the new 4K version is added to the version strip with its dimensions and a "4K" badge, the viewer switches to it, and "Descargar" downloads it. If the campaign has no active editor pipeline, the edit block is replaced by "Esta campaña no tiene editor configurado."; likewise the Entrega block reads "Esta campaña no tiene upscaler configurado." when none is active.
6. **Cola de trabajos.** Bell in the top bar with unseen count (generations by this user in this brand that are terminal and `seen_at` null). Drawer lists generations newest first with kind, status, label (prompt excerpt), detail, and thumbnails for completed ones; opening the drawer sets `seen_at`.
7. **Ajustes.** Profile (name, email), password change, "Cerrar sesión". The usage meter and team list from the prototype are shown as static placeholders labelled "próximamente".

### 6.2 `/admin` (Art Director)

Filament resources, default Filament layouts under the shared theme:

- **Brands** — CRUD; `krea_api_key` as a password field that is write-only; header action "Probar conexión" calls `GET /node-apps?limit=1` with the resolved key and reports success or the Krea error.
- **Users** — CRUD; role select; brands multi-select shown for editors.
- **Campaigns** — CRUD filtered by brand; relation manager **Pipelines**; read-only relation manager **Generations** (status, user, pipeline, error, timestamps) for support.
- **Pipelines** (via relation manager and a standalone resource) — create form: kind, label, `krea_version_id`, sort order, active. On save, `PipelineSchemaSync` runs; failure blocks saving and shows the Krea error. Edit page shows a **Fields** table editor: name, type, required (read-only) and label override, help text, visibility, fixed value, role (editable). Header action "Refrescar esquema" re-syncs and flags stale fields. Validation: one active editor and one active upscaler per campaign; editor pipelines need exactly one field with role `prompt` and at least one with role `image`; upscaler pipelines need at least one field with role `image`.

## 7. Error handling

Krea HTTP status → Spanish message (from the prototype): 400 "Solicitud inválida.", 401 "Clave de acceso inválida o faltante.", 402 "Saldo insuficiente.", 404 "No se encontró el flujo.", 500 "Error interno del servicio.", other "Error {status}.". Krea's own detail is appended when present. Network failures → "No se pudo conectar con el servicio. Intenta de nuevo en unos segundos."

- Krea `failed`/`cancelled` → Generation `failed` with `El proceso terminó con estado "{status}".`
- Poll timeout (10 min) → `failed`, `retryable`, message "Tiempo de espera agotado."
- Download failures: 3 attempts per file; if any file still fails the Generation is `failed` but pieces already stored remain, and Krea URLs are kept.
- Pipeline schema fetch failure blocks the pipeline save and shows the message inline.
- If a pipeline's fields include a stale, required field (schema drift), the Generator shows a warning banner and disables submit until an Art Director refreshes the schema.
- All Krea calls log request id, brand, pipeline, and status at `info`; failures at `warning` with the response body truncated to 2 KB.

## 8. Security

- Krea keys are encrypted at rest (`encrypted` cast) and never rendered after save.
- Editors are scoped by tenancy; every `/app` query goes through the tenant relationship. Feature tests assert cross-brand access fails.
- Uploaded inputs are validated as images ≤ 20 MB and stored in a private bucket; nothing image-related is ever written to the web server's disk.
- CloudFront signed URLs (and dev presigned URLs) expire after 10 minutes; the bucket itself is private and only reachable through CloudFront's origin access control.
- The prototype's hard-coded Krea key must be rotated by the user; the new key is entered only in `/admin` or `.env`.

## 9. Testing

- **Unit:** `PipelineSchemaSync` (new/changed/removed properties, default roles, override preservation), `PipelineFormBuilder` (each type, hidden merge, required), Krea status → message map, version-chain query including upscales, `CloudFrontSignedUrlProvider` produces a URL with `Expires`, `Signature`, and `Key-Pair-Id` for a given path.
- **Feature (Pest + `FakeKreaClient`):** full chain series generation creates pieces on `Storage::fake('pieces')`; edit generation sets `parent_piece_id`/`root_piece_id` and kind `edit`; upscale generation creates a kind `upscale` child that appears in the version chain with a 4K badge; poll backoff and timeout; download retry; Editor cannot open another brand's campaign or `/admin`; Art Director CRUD for every resource; pipeline save fails on schema error; one-editor-per-campaign validation.
- **Livewire:** Generator submit with a missing required field shows validation; Generator submit with valid data dispatches the chain; Viewer edit without editor pipeline is disabled; Viewer "Entregar en 4K" without upscaler is disabled.

## 10. Out of scope for slice 1 (later sub-projects)

2. Media library / product picker (brand-curated products, models, poses, backgrounds; optional upload to Krea `/assets`).
3. Usage quota enforcement and meter (300 images/month per brand).
4. Team management by Art Directors inside `/app`, in-app notifications beyond the bell, email digests, review links and "Enviar a revisión del estudio".
5. Corporate SSO.
6. Krea webhooks as a polling accelerator.

## 11. Local environment notes

- Machine has PHP 8.2.8 and Composer 2.1.5. Filament 5 needs PHP 8.2+ and Laravel 11.28+; Composer should be updated to 2.8+ before installing.
- MySQL is installed locally. Laravel Herd is not installed; `php artisan serve` plus `php artisan queue:work` is the dev loop, with MinIO from `docker-compose.yml` (or a dev bucket) as the object store. Docker Desktop availability should be checked at setup.
- Filament's package repository needs `composer config --auth http-basic.packages.filamentphp.com <email> <license-key>` before `composer require filament/blueprint --dev`.
- The Laravel app lives in `app/` inside this repository; `prototypes/` and `docs/` stay at the root.
