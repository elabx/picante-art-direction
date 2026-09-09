# Handoff prompt for Codex (pipeline catalog)

Paste everything below the line into Codex opened in `~/Work/picante-ai/picante-art-direction`.

---

We are building **Muse Media Ops**, a Laravel 12 + Filament 5 app for Picante studio. Slice 1 is implemented and tested locally with the fake engine. You are implementing one approved change on top of it: pipelines become a **studio-wide catalog of Krea apps** that campaigns reference, plus a seeder that creates catalog entries from `.env`.

**State of the repo (2026-09-09).** Branch `main`, HEAD `b071f95`, working tree clean. Installed: Laravel 12, Filament v5.7.8, Livewire 4, Pest. The app lives in `app/`. Full suite is green (453 passed, 1 skipped live Krea test). Nothing is deployed, so the plan rewrites the original migrations instead of adding data migrations.

Read, in this order, before doing anything:
1. `docs/superpowers/specs/2026-09-09-pipeline-catalog-design.md` (approved spec; binding when the plan is ambiguous)
2. `docs/superpowers/plans/2026-09-09-pipeline-catalog.md` (the implementation plan: Tasks 1–10)
3. `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-design.md` §5 and §7 only, for the pipeline background

**Decisions already made (apply them; do not re-litigate):**
- Catalog is studio-wide. Field configuration (labels, visibility, fixed values, roles) lives only in the catalog; no per-campaign overrides.
- Assigning an app to a campaign is activation. No per-assignment toggle. Removing the assignment is deactivation. Generated pieces stay visible because they point at immutable snapshots.
- Readiness is per catalog entry (`is_ready`). A not-ready entry disappears from every campaign's editor panel and its defaults are cleared.
- Kind rule stays: several generators, at most one editor, at most one upscaler per campaign, enforced at assignment.
- Schema sync uses the studio Krea key (`media.krea.key`). Generations keep resolving the key per brand. Do not touch `CreateGeneration::snapshot()`.
- Fixed catalog images are stored under `catalog/<uuid>` with `input_uploads.brand_id = null`.
- The seeder reads `config('media.krea.test_apps')` and never calls Krea. My local `.env` already has `KREA_TEST_APP_ID_GENERATOR`, `_EDITOR`, `_SKECHERS`, `_INVIERNO` filled and `_UPSCALER` empty (it shares the editor's id). Add the same keys empty to `.env.example`.
- Filament 5 / Livewire 4 API names in the plan (`->schema([...])` on actions, `assertSchemaComponentExists`, `assertTableActionDoesNotExist`, `TextColumn::make('pivot.sort_order')`, …) may differ from the installed version: check the vendor source or Boost's `search-docs`, adapt, keep behavior identical, and note each adaptation in the commit message. The plan already gives fallbacks for the two riskiest spots (options assertion in Task 8, pivot column).

**Environment:**
- Run everything through ddev from the repo root: `ddev composer …`, `ddev artisan …` (both execute inside `app/`), `ddev pest`, `ddev pint`, `ddev mysql`, `ddev exec -d /var/www/html/app …`. Never use host PHP, Composer, Node, or MySQL.
- After Task 1, run `ddev artisan migrate:fresh --seed --no-interaction` to rebuild the local database. Restart the queue worker afterwards: `ddev exec supervisorctl restart 'webextradaemons:*'`.
- `prototypes/` and `app/.env` contain a credential; never read, print, or commit them.

**How to work:**
- Execute the plan one task at a time, in order, starting with **Task 1**. Every task is TDD: write the failing test, run it red, implement, run green, `ddev pint --dirty --format agent`, commit with a conventional-commit message. Do not skip ahead; Tasks 1–6 leave other suites red on purpose until their task lands, and Task 10 runs the whole suite.
- Spanish UI copy verbatim from the plan.
- Do not delete tests beyond the ones the plan names explicitly (Task 8 Step 1). Do not add dependencies.
- Stop and ask me before: any real Krea call, changing migrations beyond what the plan states, or anything that leaves this repo.
- After each task, report in one short paragraph: what passed, what you changed against the plan and why, and the next task.

Start with Task 1.
