# Gate B — proposed live validation

Status: **live validation deferred; development authorized to continue**. Task 7 uses mocked HTTP requests. After this proposal the user accepted proceeding, prioritized read-only version-ID checks over balance checks, and expressly directed continued development despite imperfect node apps. No live smoke submission has run. The matrix below remains the proposed qualification scope, not evidence of passing results.

## Scope requiring approval

Keep Creador Santander v3 (`fbe97b3b-d810-4de4-859f-49aa2a7887ab`) for generation and Editor Santander v2 (`1276c054-ee4c-4ec4-8e2f-8c8b9901557b`) for editing and upscaling, as accepted after the missing Creador image input was explained.

Proposed maximum: six submissions, sequentially, within a total US$10 ceiling. This replaces the plan's eight-run matrix because the selected schemas do not expose the Skechers three-image or Invierno one-image/four-text inputs. Those two contracts would remain untested.

| Run | App / role | Inputs and acceptance evidence |
| --- | --- | --- |
| 1 | Creador / generation | `describe_la_escena`: `Fotografía de estudio de una taza roja sobre una mesa blanca, fondo gris claro, iluminación suave, sin texto.` Record returned job IDs, output count, hosts, bytes and dimensions. |
| 2 | Editor / editing | Run 1 image in `foto_para_editar`; `quiero_editar`: `Cambia únicamente el color de la taza de rojo a azul. Conserva la composición.` Check data-URL transport and returned image. |
| 3 | Editor / landscape upscale | Synthetic 800×600 test image in `foto_para_editar`; `quiero_editar`: `Amplía esta imagen a 3840 × 2880 píxeles. Conserva exactamente la composición y el contenido.` Measure actual output against the 4K rule. |
| 4 | Editor / portrait upscale | Synthetic 600×800 test image; instruction requests 2880×3840 pixels with composition and content preserved. |
| 5 | Editor / square upscale | Synthetic 800×800 test image; instruction requests 3840×3840 pixels with composition and content preserved. |
| 6 | Editor / editing | A successful upscale from runs 3–5, if available; instruction requests a small color change while retaining dimensions. Skip if no valid upscale is available. |

Synthetic inputs will contain simple color blocks and fine lines so dimension and preservation checks are inspectable. These are transport and sizing checks, not evidence of studio art-direction quality. Asking for dimensions in a prompt does not establish that the app supports them; a mismatch is a gate finding, not a passing upscale.

## Execution limits

- Build and test the smoke command with mocked HTTP before requesting paid execution approval. Do not run it against Krea until approval arrives.
- Check the workspace API balance and spending before and after each submission. Stop if the balance cannot be checked, a run costs more than US$10/6, or the remaining approved budget cannot cover the next run. Never top up funds automatically.
- Submit each run once. An ambiguous submission stops the run; do not repeat its POST. Poll already returned job IDs without resubmitting.
- Stop and report a blocking transport, schema or sizing failure before proceeding with dependent checks.
- Persist only sanitized provider response fixtures and measured metadata; keep the key, image data URLs and signed URL query strings out of fixtures and logs.

Krea documents separate API USD billing and says there is no public balance endpoint; balance monitoring must use its in-app API dashboard. See [API keys and billing](https://www.krea.ai/docs/developers/api-keys-and-billing). If that dashboard is unavailable in this session, live execution waits for a workable balance check.

## Current qualification status

Editor v2 is selected for both editing and upscaling. Both selected version IDs were rechecked successfully with read-only requests. The user has removed imperfect candidate apps as a blocker to product implementation. No paid runs, measured live outputs or real submit/job fixtures are available yet.

No engine live-contract acceptance is claimed until the approved runs produce sanitized fixtures and passing replay tests.
