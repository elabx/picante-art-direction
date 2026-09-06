# Handoff prompt for a fresh Claude Code session

Paste everything below the line into a new session opened in `~/Work/picante-ai/picante-art-direction`.

---

Use superpowers. We are building **Muse Media Ops**, a Laravel 12 + Filament 5 app for Picante studio. Planning is finished; you are executing.

Read, in this order, before doing anything:
1. `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-design.md` (the approved spec)
2. `docs/superpowers/plans/2026-09-05-muse-media-ops-slice-1.md` (the implementation plan: Tasks 0–25 plus 6b, 7b, 16b)
3. `docs/superpowers/specs/2026-09-04-muse-media-ops-slice-1-review.md` (review findings and their disposition)
4. `docs/superpowers/plans/2026-09-05-codex-plan-review.md` (already applied; see the plan's Revision log, no pending fixes)

Then:
- Run `/codex:setup` first (Task 0 of the plan).
- Execute the plan with `superpowers:subagent-driven-development`, one task at a time, in order. Every task is TDD: failing test, then code, then green, then commit.
- Working directory for app commands is `app/` (create it in Task 1). `prototypes/` is git-ignored and contains a credential; never read the key or commit that folder.
- Stop and ask me before: entering Filament license credentials (I run the `composer config --auth` line myself), fetching real Krea schemas (Task 6b needs the fresh key and version IDs), spending Krea credit (Task 7b, ceiling US$10), or anything that leaves this repo.
- Hard rules from the spec: Spanish UI copy verbatim from the plan; standard Filament layouts; all images in object storage (MinIO locally, S3 + CloudFront signed URLs in prod); Krea key never in browser, logs, snapshots or queue payloads; never call the provider `submit` twice for one generation.
- If Filament 5 / Livewire 4 signatures differ from the plan's snippets, use Laravel Boost's `search-docs` on the installed version and adapt, keeping behavior identical. Note every such adaptation in the commit message.
- After each task, report in one short paragraph: what passed, what you changed against the plan and why, and the next task.

Start with Task 0.
