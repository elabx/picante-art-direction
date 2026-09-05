# Muse Media Ops — Slice 1 design review

**Date:** 2026-09-04
**Disposition:** Product decisions incorporated; engineering corrections drafted. Documentation/prototype research is complete; the [Krea/Comfy recommendation](../research/2026-09-04-engine-recommendation.md) specifies the remaining provider-dependent amendments and evidence gates. No implementation or live integration checks have been completed.
**Reviewed document:** [Slice 1 design](2026-09-04-muse-media-ops-slice-1-design.md).
**Scope:** Design consistency, prototype coverage, failure recovery, tenancy, storage, and acceptance criteria. Findings and line references below record the original reviewed version. The follow-up section identifies subsequent resolutions in the design; a resolution here means specified, not implemented or runtime-tested.

The campaign → generator → gallery → viewer flow is represented well. Moving credentials and result downloads to the server addresses limitations visible in the browser prototype. This pass adds concrete concerns about upload-path authorization, pipeline readiness, UI refresh, and concurrent activation to the earlier review.

## Follow-up after user decisions — 2026-09-04

The user approved a 3,840-pixel longest edge with preserved aspect ratio, shared curation, and discretion to keep Editor controls minimal. They removed campaign statuses and deferred visual fidelity in favor of standard Filament layouts. The design now reflects those choices throughout its decisions, data model, screens, and acceptance checks.

The user also approved recovery of existing jobs first, with Editors allowed to start another potentially billable generation through a confirmation modal. The design now specifies Spanish modal copy, cancellation behavior, a final original-job check, linked replacement records, and deduplication of the confirmation request.

**Earlier checkpoint:** While drafting the remaining technical corrections, the user introduced Comfy API as a possible primary/secondary engine. The design contains candidate contracts for uploads, snapshots, child jobs/output manifests, schema readiness, exports, and UI refresh. The table below records that earlier review checkpoint and is not a claim that the draft is implementation-ready. The research reconciliation immediately below supersedes statements about research still being pending.

| Finding | Disposition at the earlier checkpoint |
| --- | --- |
| R1 — framework versions | Resolved in the specification: Livewire 4 and Tailwind 4.1+ |
| R3 — recovery and restart | Product behavior is resolved: existing-job recovery first, explicit confirmation for another execution, and local-timeout/unknown-submission reasons. Durable submission-attempt guards, output manifests, and full worker recovery remain open |
| R7 — pipeline bindings and readiness | Resolved in the specification: save inactive, configure before activation, one automatic image binding, edit instruction binding, and Art Director-configured extra values |
| R9 — root-piece rule | Resolved in the specification: explicit root fallback and deterministic ordering |
| R10 — selection and export | Shared marks, separate temporary download selection, and export input snapshots are decided. Export persistence, recovery, authorization, and lifecycle remain open |
| R11 — 4K delivery | Resolved in the specification: exact target dimensions, aspect-ratio validation, output retention on a failed delivery, and no inherited badge on edits. Actual Krea pipeline sizing still needs integration verification |
| Prototype visual fidelity | Deferred by user decision. Standard Filament layouts replace the custom-theme and screenshot-matching recommendation for this slice |
| R2, R4–R6, R8, R12–R13 | Remain open engineering findings |

### Research reconciliation — 2026-09-04

The [lead recommendation](../research/2026-09-04-engine-recommendation.md) reconciles the two source-linked reports and recommends Krea primary/only adapter initially, with Comfy deferred as an explicitly configured per-pipeline option. Automatic fallback remains outside the approved recovery policy. This is a recommendation, not a newly approved engine decision.

The current design already drafts the submission claim, execution snapshots, all child job IDs, output manifests, owned uploads, export persistence, UI refresh, and campaign-locked activation. Consequently, the original findings below and the preceding disposition table are historical evidence; statements that these mechanisms are entirely absent, or that the design still selects only the first job, no longer describe the latest draft. They still require implementation acceptance checks. The Gallery's image-only and multi-text summaries are also specified in §6.1.

Remaining design amendments are explicit in the recommendation: image preparation before the submission claim; provider-native mappings and schema-null handling; stable output identity rather than signed-URL identity; provider-aware retrieval/expiry; dynamic target-size bindings; aggregate input limits; and complete acceptance coverage. Comfy's duplicate-key rejection does not recover a lost job ID. Krea's current bearer API billing uses USD balance rather than app compute units.

Actual workflow access, schemas/output fixtures, image transport, exact 3840 sizing, art-direction quality, and costs remain unverified. The implementation plan is not finalized; the recommendation identifies the missing evidence and bounded experiment proposals. All settled product decisions remain preserved.

P1 findings should be resolved before building the affected foundation. P2 findings should be resolved before implementing or accepting the affected feature. These are design findings; no application implementation exists here to demonstrate runtime failures.

## Findings

### R1 — P1: Correct the framework baseline

**Reference:** §3, line 24.

Filament 5 and Livewire 3 are incompatible. Specify Livewire 4+ and lock a compatible dependency set when scaffolding. Filament's installation documentation also specifies Tailwind 4.1+.

**Acceptance:** A clean installation resolves the chosen versions and serves both panels. Sources: [Filament upgrade requirements](https://filamentphp.com/docs/5.x/upgrade-guide), [installation](https://filamentphp.com/docs/5.x/introduction/installation).

### R2 — P1: Authorize uploaded paths and command relationships explicitly

**References:** §4.3–4.5, lines 80–103; §8, line 160.

The shared `inputs` disk accepts image paths through form state, then the worker reads those paths with server credentials. Tenant-scoped database queries do not establish ownership of a submitted storage path. A tampered path could reference another brand's upload if its path is known. Filament documents this behavior and provides `preventFilePathTampering()`; create forms and reusable uploads also need an ownership-aware allowance. Source: [Filament file-path authorization](https://filamentphp.com/docs/5.x/forms/file-upload#authorizing-existing-file-paths).

Specify an upload ownership record or equivalent authoritative ownership check. Validate that campaign, selected pipeline, parent piece, and export members belong to the intended campaign/brand. Signing an object URL must follow authorization of its owning record. Workers must derive their brand context from persisted, validated records; panel tenant context is unavailable outside panel requests. Source: [Filament tenancy scope limitations](https://filamentphp.com/docs/5.x/users/tenancy).

**Acceptance:** Submit another brand's upload path, pipeline ID, parent piece ID, and export piece ID through tampered Livewire requests. Reject them before any Krea request, object read, or signed URL is produced. Also reject an unrelated campaign's pipeline even when both campaigns share a brand.

### R3 — P1: Define recovery around durable execution and output state

**References:** §4.4, lines 90–93; §7, lines 150–152.

The worker can die after Krea accepts an execution but before `krea_job_id` is saved. Blindly retrying submission may launch another execution. A ten-minute local timeout also does not prove Krea failed or stopped. Define an ambiguous-submission state and recovery policy; do not promise exactly-once remote execution without verified provider support.

Once a Krea job ID is known, retries should resume that execution. Guard state transitions and output insertion against duplicate delivery. Persist an output manifest before downloading so URLs for failed downloads survive independently of successfully created Pieces. Define empty/malformed results as errors rather than completing an empty loop successfully. Handle transient polling failures and exhausted worker attempts so generations cannot remain non-terminal indefinitely.

**Acceptance:** Exercise worker interruption around submission persistence, duplicate poll/download delivery, temporary polling failure, one failed file among several successful files, and a Krea job completing after the local waiting deadline. Recovery must retain successful pieces and avoid resubmitting known executions.

### R4 — P1: Snapshot execution configuration at submission

**References:** §4.4, line 91; §5, line 115.

`Generation` stores a pipeline reference and inputs, while the worker resolves fixed values and field mappings later. An Art Director changing the version ID, mappings, or fixed values before the worker runs changes the meaning of an already accepted request.

Store an immutable execution specification: Krea version ID, resolved typed inputs, automatic parent-image/prompt mappings, and the configuration revision used. Retain stable uploaded objects while accepted work depends on them. Resolve secrets separately; this is not a recommendation to copy plaintext credentials into every Generation.

**Acceptance:** Queue a generation, modify its pipeline and fixed values, then run the worker. It must execute the original accepted specification and retain understandable historical prompt metadata.

### R5 — P1: Make schema reconciliation recoverable

**References:** §4.2, line 70; §7, line 154.

Removed properties stay stale, but a stale property that used to be required disables generation until another refresh. That refresh leaves the removed property stale, so the proposed recovery cannot clear the block.

Determine readiness from the current schema and current mappings. Removed properties should be excluded from both rendering and submitted fixed values. New required properties and incompatible overrides need explicit reconciliation before activation. Refresh alone must not silently approve incompatible configuration.

**Acceptance:** Removing an old required property can reach a ready state; adding a new required property blocks execution only until its current input configuration is valid.

### R6 — P2: Specify a supported schema subset and editable semantic types

**References:** §4.2–4.3, lines 69–86; §6.2, line 144.

URI fields are not necessarily images, and an image description can mention an image while remaining text. Yet inferred field type is read-only. The supplied Skechers prototype requires three image fields (`imagen_de_refe`, `modelo`, `tenis`), while Invierno requires one image and four text fields; neither is adequately verified by the single-prompt happy path.

Allow an explicit semantic input-type override while retaining the source schema. Specify support or activation-time rejection for enums, integers, arrays, objects, nullable values, and constraints. Preserve JSON types for hidden fixed values instead of relying on text coercion. Reject hidden required fields with no valid value. Live schemas for the actual configured versions remain to be verified; the names alone do not prove their schemas are defective.

**Acceptance:** Fixtures reproduce both prototype payloads and validate a hidden numeric value, a false boolean, an enum constraint, and an unsupported structured field.

### R7 — P2: Align pipeline readiness with what the Viewer can supply

**References:** §4.4, lines 95–97; §6.1, line 133; §6.2, line 144.

Editor validation permits multiple image-role fields and additional required visible fields, but the Viewer supplies one parent image and one instruction. It provides no form for other editor inputs. The upscaler similarly permits multiple image roles without defining which one receives the selected piece. Hidden fixed values may also conflict with automatic prompt/image bindings unless precedence is defined.

Define exactly one automatic source-image binding for edit/upscale and one instruction binding for edits. Either render additional editor fields or require them to have valid configured values. Separate draft creation/schema sync from activation so the Art Director can fix mappings before readiness is enforced. Define the zero-active-generator campaign state.

**Acceptance:** Create a draft pipeline whose required mappings need manual correction, configure it, and activate it. An editor with a second required input cannot become runnable unless the UI or configuration supplies it.

### R8 — P2: Complete temporary-upload and signed-delivery behavior

**References:** §4.5, line 103; §8, lines 161–162.

Configuring the final FileUpload disk does not itself specify temporary storage. Livewire uses its default filesystem disk for temporary uploads and has a separate default 12 MB validation limit. Specify an S3 temporary disk, a limit consistent with 20 MB, cleanup, private-file validation, and temporary preview handling. Source: [Livewire uploads](https://livewire.laravel.com/docs/4.x/uploads).

Define how the custom CloudFront signer integrates with previews and downloads. A page left idle beyond ten minutes needs fresh authorization/signing when a new image request or download occurs; cached images need not disappear, but expired URLs cannot authorize new requests. Source: [CloudFront signed URLs](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-signed-urls.html).

Clarify the bucket policy wording: browser delivery goes through CloudFront, while authorized application reads/writes and direct temporary uploads need appropriate S3 access.

**Acceptance:** A real MinIO upload between 12 and 20 MB succeeds without local image storage; a staging private preview and an idle-page download work. Storage fakes and a test for signature query-parameter names are insufficient evidence for these integrations.

### R9 — P2: State the root-piece rule explicitly

**References:** §4.4, lines 95–97; §5, line 118.

Originals have null `root_piece_id`, while children inherit the parent's root. The prose may intend the conceptual root, but directly copying the nullable field would orphan the first edit/upscale from the specified chain query.

Use `root_piece_id = parent.root_piece_id ?? parent.id`. Preserve the immediate parent separately and add deterministic ordering for equal timestamps.

**Acceptance:** Original → edit → upscale → edit and two sibling edits all appear once under the original root, including after reload.

### R10 — P2: Design the queued ZIP export

**References:** §5, line 116; §6.1, line 132.

The UI promises an asynchronous export, but no export persistence, selection snapshot, failure state, object lifecycle, or download authorization is specified. `pieces.selected` also makes selection shared between all Editors who can access a piece; that may be intended but is not stated.

Decide whether selection is shared curation or personal download selection. Store the requested piece IDs at export submission with owner, brand, state, output path, and expiry. Specify streaming or another bounded-memory archive strategy consistent with the object-storage requirement.

**Acceptance:** Two Editors' selection behavior matches the chosen semantics. Changing selection after export submission does not change its contents. Export failures and expired downloads have recoverable UI states.

### R11 — P2: Define the 4K delivery contract

**References:** §4.4, line 97; §6.1, line 133.

Every output of an upscaler is currently labelled 4K without a target dimension rule. Decide the target for landscape, portrait, and square outputs, aspect-ratio preservation, accepted file formats, and how below-target output is reported. Editing a 4K image can change its dimensions, so quality labels should follow verified output properties.

**Acceptance:** A successful upscaler response with an undersized image is not displayed as satisfying 4K delivery. Actual output dimensions remain visible.

### R12 — P2: Give the bell and Gallery Viewer a refresh mechanism

**References:** §6.1, lines 131–134.

The only specified polling is on the Generator and scoped to its campaign. Start a job, navigate to Campañas or Ajustes, and the bell has no specified way to discover completion. An edit/upscale launched from the Gallery Viewer similarly needs its own refresh path.

Specify a panel-level job-status component or equivalent refresh mechanism, plus targeted Viewer refresh. Only mark terminal items actually presented as seen; opening a drawer while work is still running must not suppress the later completion notification.

**Acceptance:** Start work, navigate away, and observe the bell update without reloading. Run an edit from Gallery and observe the new version appear. An unfinished item opened in the drawer becomes unseen on later completion.

### R13 — P2: Enforce active-pipeline and default-pipeline invariants atomically

**References:** §5, lines 112–113; §6.1, line 128; §6.2, line 144.

Two concurrent validation checks can both observe no active editor and then save two active editors. The runner assumes a single choice. The default generator reference also lacks an explicit same-campaign, generator-kind, active-state rule.

Make activation atomic, for example by serializing it under a transaction lock on the campaign. Validate default selection against that campaign's active generators and define fallback when the default is deactivated or removed.

**Acceptance:** Concurrent activations cannot leave two active editors or upscalers. A default pipeline from another campaign, or an editor selected as a generator default, is rejected.

## Prototype coverage and remaining evidence

- Intentional exclusions are clear: addons dashboard, curated library/product picker, quota enforcement, team controls, SSO, and review-sharing workflows. This review does not recommend restoring them to slice 1.
- Preserve fixtures from [Fotos Skechers](../../../prototypes/App%20Krea%20con%20flujos%20de%20usuarios/uploads/fotos-skechers.html) and [Generador Invierno](../../../prototypes/App%20Krea%20con%20flujos%20de%20usuarios/uploads/generador-invierno.html), along with the full canvas generator/editor flow. The input shapes above come from local prototype source.
- Krea's execute documentation shows an array response, but the design keeps only the first job. Verify cardinality for the actual pipeline versions. If multiple jobs are possible, represent and track all returned IDs. This is an unresolved integration assumption, not a confirmed claim that the supplied pipelines return multiple jobs. Source: [Krea execute API](https://www.krea.ai/docs/api-reference/node-apps/execute-a-node-app).
- Record sanitized real schema, submission, completed, failed, and output payload fixtures. No authenticated Krea requests or paid generations were performed during this review.
- Define the Gallery's prompt display for image-only Skechers generations and multi-prompt Invierno generations. A single generic “latest prompt” cannot describe all those inputs faithfully.
- The initial review recommended custom Editor layouts and visual comparison. The user subsequently deferred that work: use standard Filament layouts and verify functional behavior at desktop and narrow widths. Prototype screenshot matching is not required for this slice. No rendered visual comparison was performed in this review.

## Suggested revision order

1. Correct dependencies and establish authorization, execution snapshots, recovery, and schema readiness.
2. Specify edit/upscale bindings, version lineage, output validation, exports, and shared selection semantics.
3. Complete object-storage delivery and UI refresh contracts.
4. Add the acceptance scenarios above to §9, then write the implementation plan in dependency order.

This review does not require changing the chosen Laravel/Filament architecture. It requires enough detail that an implementer does not have to invent the behavior that protects tenant data, paid executions, and generated assets.

## Disposition after convergence — 2026-09-05

Decisions taken with the user on 2026-09-05: Krea only with a small `ImageEngine` seam and provider-neutral column names; essential recovery set in slice 1 with ZIP export, 24-hour reconciler, revision counters, and host allowlist moved to slice 2; Modernist theme named as slice 2; convergence pass done by the planning session. The design now reflects these. "Resolved" below means specified in the design, not implemented or runtime-verified.

| Finding | Disposition |
| --- | --- |
| R1 framework baseline | Resolved: Livewire 4, Tailwind 4.1+ |
| R2 upload path authorization | Resolved: `input_uploads` ownership records, ID-based commands, relationship checks, signing after authorization |
| R3 durable execution | Resolved for slice 1: `submitting` claim, all job IDs kept, output manifest, `submission_unknown`, no automatic resubmission, manual "Comprobar estado". Deferred to slice 2: per-minute reconciler and 24-hour background recovery |
| R4 execution snapshot | Resolved: `execution_snapshot` with pinned credential source |
| R5 schema reconciliation | Resolved: readiness from current schema; removed required fields no longer block |
| R6 schema subset and semantic types | Resolved: declared primitive subset, editable `input_type`, typed fixed values with `has_fixed_value` |
| R7 bindings and readiness | Resolved: one image + one prompt binding for editors, one image for upscalers, save-inactive then activate |
| R8 uploads and signed delivery | Resolved in design: S3 temporary disk, 20 MiB rule, URL refresh at click and before expiry. Runtime verification is Gate B |
| R9 root-piece rule | Resolved: `root_piece_id = parent.root_piece_id ?? parent.id`, ordering by `created_at`, `id` |
| R10 ZIP export | Deferred to slice 2 by decision; slice 1 offers per-piece download. Shared-curation semantics resolved |
| R11 4K contract | Resolved: 3,840 px longest edge, validated dimensions, `is_4k` flag |
| R12 bell refresh | Resolved: panel-level 5 s polling component; revision counters deferred to slice 2 |
| R13 atomic activation | Resolved: transaction lock on campaign, default-pipeline validation |
| Engine research | Krea first, Comfy deferred to slice 5. Five-operation adapter not adopted; four-method `ImageEngine` with provider-neutral columns instead |
| Prototype visual fidelity | Deferred to slice 2 as a named slice |
