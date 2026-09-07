# Gate B — tooling and outstanding live evidence

## Status

Live qualification is deferred. The user explicitly instructed: "let's continue even if node apps are not exactly perfect". Product implementation continues with truthful capability checks; this is not a passing live gate.

No execution endpoint was called in this work. No paid smoke outputs or real submit/job/result fixtures have been captured. Mocked command and adapter tests verify local behavior only. Do not count generated test responses as real Krea evidence.

## Selected apps and verified evidence

- Generator: Creador Santander v3, `fbe97b3b-d810-4de4-859f-49aa2a7887ab`.
- Editor and candidate upscaler: Editor Santander v2, `1276c054-ee4c-4ec4-8e2f-8c8b9901557b`.
- Read-only revalidation on 2026-09-07 at 18:01:23 UTC returned HTTP 200 for both IDs, exact matching version IDs, and input schemas.
- Creador exposes `describe_la_escena`; Editor exposes image input `foto_para_editar` and optional text `quiero_editar`. Neither exposes size or scale controls.

See [Gate A schemas](2026-09-07-gate-a-schemas.md) for sanitized schema fixtures and [the live-run proposal](2026-09-07-gate-b-proposal.md) for the qualification matrix.

## Findings carried into implementation

1. Task 9 must recognize the provider's `x-krea-wire-type` text/image annotation without treating unrelated unsupported keywords as valid.
2. Task 10's field heuristics must recognize `foto_para_editar` as an image candidate and `quiero_editar` as an editing prompt candidate; the Art Director still confirms bindings.
3. Task 14 must not invent a width, height or scale input for these schemas. A fixed upscale instruction can be configured on the optional text field, but does not establish control over output dimensions.
4. Task 16 must keep the exact 4K acceptance rule. A mismatched output is retained for inspection/download, marked with the specified delivery-dimension failure, and receives no 4K badge.

## Still unverified

Real image data-URL transport; actual per-app job and output counts; output hosts; image dimensions and quality; 4K size control; execution cost; Skechers/Invierno multi-input contracts. A future live run must record these findings and add replay tests before the integration is described as live-validated.
