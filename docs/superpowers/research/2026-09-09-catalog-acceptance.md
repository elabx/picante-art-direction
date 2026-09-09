# Catalog acceptance — 2026-09-09

## Automated verification

- `ddev pest --compact`: 488 passed, 1 skipped, 2122 assertions. The skipped test is the deferred live Krea test.
- `ddev pint --dirty --format agent`: passed.
- `git diff --check`: clean. No legacy activation calls or `is_active` references remain in application PHP; no `flujo` wording remains in Filament resources.
- Local database rebuilt with `ddev artisan migrate:fresh --seed --no-interaction`, then queue worker and scheduler restarted. Seeder created four catalog entries and skipped the empty upscaler ID without contacting Krea.
- Automatic availability was tested red then green: create loads the schema and enables valid apps; schema and field changes recompute availability; invalid configuration clears campaign defaults; correcting it restores availability. Manual readiness actions are absent. The update action reads “Actualizar esquema”.

## Browser observations

- Signed in locally as the seeded art director and saw all four seeded catalog entries.
- After the final UX simplification and database rebuild, the catalog displayed “Disponible” and “Actualizar esquema”, with no manual readiness actions. Loading the generator schema changed availability from “No” to “Sí” automatically.
- Saved its prompt field label as “Describe la escena” and help text as “Describe la pieza que quieres generar.” The edit page showed configuration revision 3 and availability “Sí”.
- Assigned Creador Santander v3 to the local acceptance campaign and saw it in the campaign's Apps table. Reloaded, selected it as “Generador por defecto”, and saved the campaign.
- The default selector needed a reload after assignment. Added and tested an event that refreshes its options after assigning/removing apps without discarding unsaved campaign edits; the corrected interaction still needs browser confirmation.
- Logged out as art director. The editor login/generation browser check remains pending because active Chrome tabs changed during navigation. Automated generation tests passed; this is not a claim of completed browser acceptance.
- No real Krea calls were made.

## Plan adaptations and remaining decision

- The walkthrough is in `app/README.md`; there is no root README.
- The user's latest instructions replace manual readiness with automatic validation and rename “Refrescar esquema” to “Actualizar esquema”. Seeded entries still require schema loading because the seeder must never call Krea.
- The user also requested `/picante` as the administrative URL. The panel path, route tests, and README now use `/picante`; internal Filament panel and route names retain `admin`. The browser observations above preceded this URL change.
- Review fixes cover archived campaign assignments in deletion authorization, a selected app being detached while the generator is open, clearing defaults on failed readiness validation, and retrying database deadlocks in the affected outer transactions. `CreateGeneration::snapshot()` remains unchanged.
- The user approved the additional original-migration change: `generations.pipeline_id` is nullable with `nullOnDelete()`. A regression test first reproduced the restrictive-FK deletion failure, then passed with the change, verifying that generations, pieces, and immutable snapshots survive deletion of an unassigned app. The local database was rebuilt again after this change. Generation history displays the app label from its snapshot. Task 10 still awaits the final browser walkthrough.
