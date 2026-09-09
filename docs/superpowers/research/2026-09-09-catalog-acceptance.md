# Catalog acceptance — 2026-09-09

## Automated verification

- `ddev pest --compact`: 486 passed, 1 skipped, 2108 assertions. The skipped test is the deferred live Krea test.
- `ddev pint --dirty --format agent`: formatting applied; final clean check recorded with the implementation commit.
- `git diff --check`: clean. No legacy activation calls or `is_active` references remain in application PHP; no `flujo` wording remains in Filament resources.
- Local database rebuilt with `ddev artisan migrate:fresh --seed --no-interaction`, then queue worker and scheduler restarted. Seeder created four catalog entries and skipped the empty upscaler ID without contacting Krea.
- Automatic availability was tested red then green: create loads the schema and enables valid apps; schema and field changes recompute availability; invalid configuration clears campaign defaults; correcting it restores availability. Manual readiness actions are absent. The update action reads “Actualizar esquema”.

## Browser observations

- Signed in locally as the seeded art director and saw all four seeded catalog entries.
- Before the final UX simplification, updated the generator schema using the local fake engine and observed “Esquema actualizado.” Opened its field configuration and edit modal.
- These observations do not verify the final automatic-availability UI. After rebuilding the database, the attempted reload was interrupted by a change of the active Chrome window.
- Campaign assignment, default selection, and editor generation through the browser remain pending. Their automated tests passed; this is not a claim of browser acceptance.
- No real Krea calls were made.

## Plan adaptations and remaining decision

- The walkthrough is in `app/README.md`; there is no root README.
- The user's latest instructions replace manual readiness with automatic validation and rename “Refrescar esquema” to “Actualizar esquema”. Seeded entries still require schema loading because the seeder must never call Krea.
- Review fixes cover archived campaign assignments in deletion authorization, a selected app being detached while the generator is open, clearing defaults on failed readiness validation, and retrying database deadlocks in the affected outer transactions. `CreateGeneration::snapshot()` remains unchanged.
- Deleting an unassigned app with historical generations is still restricted by the original `generations.pipeline_id` foreign key. An additional one-line migration change to nullable + `nullOnDelete()` was proposed but has not been applied: the user requires approval for migrations beyond the plan. Task 10 remains open pending that decision and the final browser walkthrough.
