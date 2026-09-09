# Editor acceptance and viewer improvements — 2026-09-09

Browser clicks are working again. The user created the Example brand and campaign `campana-de-pruieba`; the browser walkthrough then created and activated generator/editor/candidate-upscaler pipelines 1–3 with the selected Krea version IDs. The upscaler's optional text field is hidden with a fixed instruction and no prompt binding. No provider qualification is implied.

After explicit user approval, assigned existing local editor `test@example.com` to Example through administration. Signed out of admin and signed into the editor panel with the existing demo account. No password changes.

Before generation, temporary diagnostics confirmed both the actual web process and supervised worker reported `local`, fake flag enabled, and FakeEngine bound. Diagnostic files were removed. No real provider execution or paid resources.

## Observed browser/server results

- Editor sees the campaign and can open its generator.
- Generation 1: one series completed; four 4 × 3 legacy fake pieces stored; browser refreshed automatically.
- Generation 2: edit of piece 1 completed; viewer automatically switched to a new version.
- Canceling the 4K modal left generation count at two.
- Generation 3: confirmed upscale of piece 8 produced four stored pieces and `delivery_dimensions` failure. Viewer displayed the Spanish error and no 4K badge; database confirmed zero 4K pieces.
- Shared selection toggled in the viewer. Main image loaded with natural dimensions matching storage.

## User-requested improvements

Replaced the local demo's black 4 × 3 output with four distinct 1024 × 768 product mockups. These are deterministic illustrations labeled as simulation, not prompt-responsive AI output. Existing stored pieces remain unchanged. Restarted worker/scheduler to load the new fixtures.

Reworked the viewer into an image-led layout with bounded preview height, fixed-size version thumbnails, visible active-version border, a spaced edit form, compact download/4K row, shared selection, and collapsible origin details. Removed the oversized Entrega section and made upscale a secondary button. A narrow-screen media query stacks the columns; narrow-screen visual acceptance remains pending.

Generation 4 produced the new mockups (pieces 13–16), observed in the browser. Desktop screenshot verified the settled compact layout and expandable origin details. Submitted a further fake edit through the redesigned form to verify the primary action.

Validation: demo regression failed first on legacy dimensions, then passed with 1024 × 768 dimensions and four distinct file hashes. Viewer/demo focused suite: 33 passed, 172 assertions. Full suite: 450 passed, one deferred live test skipped, 1,944 assertions. Pint: 198 files passed.

## Remaining work

Continue browser acceptance for gallery filters, actual download, jobs bell seen state, foreground switching, settings/logout, authorization revocation, mobile layout, and expiry recovery. English editor page titles/Stats and raw select accessibility translation keys were observed and remain to address. No blanket browser acceptance claim. Demo seeder with separate configuration was requested and then explicitly deferred by the user.

Real Krea quality/4K qualification and Cloud/AWS deployment remain deferred. Nothing pushed or deployed.

## Queue panel follow-up

Replaced the dropdown with the same native Filament modal pattern used by database notifications: right slide-over, body teleport, sticky header, autofocus on the panel, and standard close/focus behavior. Consulted installed Blueprint action/custom-page guidance, Boost SearchDocs, and the installed database-notifications Blade implementation. The `x-modal-opened` event now marks displayed terminal jobs seen instead of inspecting a dropdown's inline display style.

The queue icon is `queue-list`; active count takes badge precedence over unseen results. Active work is ordered ahead of recent terminal jobs so it cannot be displaced by a full page of newer results. Sections are En curso and Recientes, with colored status badges and result thumbnails. Added folder/settings sidebar icons.

Browser verified: right-side slide-over, rendered sidebar icons, marking displayed results seen (database count zero), and thumbnail transition closing the queue and opening the piece viewer. Regression suite: 453 passed, one skipped, 1,959 assertions. Campaign 1 has no saved cover, explaining the empty Portada cell.

AI setup audit: Boost/Blueprint packages and documentation bridge are present, but `app/boost.json` targets Claude Code with four generic skills. The installed agent skills do not include the `filament-development` / `planning-filament` skills recommended by the current Filament AI guide. Installation/configuration follow-up remains pending; reading package guidance is not equivalent to fully configured agent skills.
