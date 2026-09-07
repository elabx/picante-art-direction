# Handoff prompt for Codex (continue from Task 2)

Paste everything below the line into Codex opened in `~/Work/picante-ai/picante-art-direction`.

---

We are building **Muse Media Ops**, a Laravel 12 + Filament 5 app for Picante studio. Planning is finished and execution has started; you are continuing it.

**State of the repo (2026-09-07).** Branch `slice-1` (from `main`), HEAD `0cd9726`. Task 0 (Codex setup, ddev check) and Task 1 (Laravel scaffold) are done and committed. Installed: Laravel 12.69.1, Filament 5.7.8, Livewire 4.4.3, Pest 3.8, Laravel Boost, Filament Blueprint. The app lives in `app/`. `ddev pest` is green (2 example tests, MySQL `muse_test` via RefreshDatabase). Task 1 was not code-reviewed; review commit `0cd9726` briefly before starting Task 2 and fix anything wrong in a separate commit.

Read, in this order, before doing anything:
1. `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-design.md` (the approved spec; binding authority when the plan is ambiguous)
2. `docs/superpowers/plans/2026-09-05-muse-media-ops-slice-1.md` (the implementation plan: Tasks 2–25 plus 6b, 7b, 16b remain)
3. `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-review.md` (review findings and their disposition)
4. `docs/superpowers/plans/2026-09-05-codex-plan-review.md` (your earlier review; already applied, see the plan's Revision log)

**Rulings already made during pre-flight (apply them; do not re-litigate):**
- `OutputStatus` keeps a `downloading` case (plan Task 3/16 claim state) even though spec §5 lists three values.
- `PipelineField` casts `needs_configuration` as bool (Task 4 interface lists it; the cast snippet omits it).
- `GenerationFactory::snapshot(array $overrides = []): array` is a static that holds the default snapshot fixture; the Pest helper `snapshot()` delegates to it; from Task 7 on the factory default `execution_snapshot` uses it.
- Add `ping()` to `FakeEngine` as a no-op recorder so the admin "Probar conexión" action (Task 18) works against whatever `EngineResolver` returns.
- `SchemaSubset` and its unit test are created in Task 9 (as Task 9 step 2 says); Task 10 does not re-create them. Implement `classify` without the early return in the plan snippet: keyword errors and a "Tipo no soportado: <type|desconocido>" error accumulate, so the test's expected counts (2 for `array`, `$ref`, `oneOf`; 1 for `x-custom`) hold.
- `Pipeline::fields()` orders by `sort_order` then `id`; `InputComposer` iterates in that order (the Task 14 test compares associative arrays with `toBe`).
- Test helpers `readyGenerator()` (Task 15) and `editorInCampaign()` (Task 21) live in `tests/Pest.php`, not in test files.
- Task 16: `PollGenerationJob` takes an optional `?\DateTimeInterface $windowStartedAt` (default: the generation's `submitted_at`); `RestartGeneration::checkStatus` passes `now()` and re-dispatches carry it forward. `settle()`/polling may move a `failed` generation whose reason is `poll_timeout` or `download_failed` forward to `downloading`/`completed` (spec §4.4 late completion).
- Task 17 keeps the Dashboard page that `make:filament-panel` generated; Task 20 removes it when the Campaigns page takes slug `''`.
- Filament 5 / Livewire 4 test and component API names in the plan (`callTableAction`, `assertActionDisabled`, `mountAction`, `allowFilePathUsing`, …) may differ: use Boost's `search-docs` on the installed version, adapt, keep behavior identical, and note each adaptation in the commit message.

**Environment findings from Task 1:**
- Run everything through ddev from the repo root: `ddev composer …`, `ddev artisan …` (both execute inside `app/`), `ddev pest`, `ddev pint`, `ddev mysql`, `ddev mc …`, `ddev exec -d /var/www/html/app …`. Never use host PHP, Composer, Node, or MySQL.
- `ddev npm` runs in `/var/www/html`, not `app/`; use `ddev exec bash -c 'cd /var/www/html/app && npm …'`.
- Restart the queue worker and scheduler after changing job code: `ddev exec supervisorctl restart 'webextradaemons:*'`.
- `prototypes/` is git-ignored and contains a credential; never read it. Filament credentials live in the git-ignored `.ddev/homeadditions/.composer/auth.json`; never print or commit them.

**How to work:**
- Execute the plan one task at a time, in order, starting with **Task 2**. Every task is TDD: write the failing test, run it red, implement, run green, commit with a conventional-commit message. Before each Filament resource task (18, 19), apply Blueprint's planning guidance as the plan says.
- Stop and ask me before: changing the PHP version if a dependency refuses 8.5, fetching real Krea schemas (Task 6b needs the fresh key and version IDs), spending Krea credit (Task 7b, ceiling US$10), or anything that leaves this repo.
- Hard rules from the spec: Spanish UI copy verbatim from the plan; standard Filament layouts; all images in object storage (MinIO locally, S3 + CloudFront signed URLs in prod); the Krea key never reaches the browser, logs, snapshots, or queue payloads; never call the provider `submit` twice for one generation.
- After each task, report in one short paragraph: what passed, what you changed against the plan and why, and the next task.

Start by reviewing commit `0cd9726`, then Task 2.
