# Gate A — selected Krea schemas

Date: 2026-09-07. Two authenticated, read-only requests to `GET https://api.krea.ai/node-apps/{versionId}` returned HTTP 200. No execution endpoint was called and no generation credit was spent.

## Requested role mapping

The user initially selected Creador Santander v3 for all roles, then changed the upscaler to Editor Santander v2.

| Role | Requested version | Fixture |
| --- | --- | --- |
| Generator | `fbe97b3b-d810-4de4-859f-49aa2a7887ab` — CREADOR SANTANDER | `app/tests/Fixtures/krea/schema-generator.json` |
| Editor | Same Creador version, pending correction below | `app/tests/Fixtures/krea/schema-editor.json` |
| Upscaler | `1276c054-ee4c-4ec4-8e2f-8c8b9901557b` — EDITOR SANTANDER | `app/tests/Fixtures/krea/schema-upscaler.json` |

The supplied Skechers version was not fetched because the user's subsequent role selection did not use it. One request was made per distinct selected version; the shared generator/editor response was saved under both role filenames.

## Observed inputs

| Version | Property | Transport type | Required | Provider annotation | Other constraints |
| --- | --- | --- | --- | --- | --- |
| Creador | `describe_la_escena` | string | yes | `x-krea-wire-type: text` | none |
| Editor | `foto_para_editar` | string | yes | `x-krea-wire-type: image` | `format: uri` |
| Editor | `quiero_editar` | string | no | `x-krea-wire-type: text` | none |

Both schemas are non-null, flat objects. Neither exposes width, height, target dimensions, scale, enums, or defaults.

## Findings and downstream implications

- Creador supports the selected generator form, with `describe_la_escena` as the prompt.
- Creador has no source-image input. It cannot satisfy the approved editor contract, which needs one image binding and one instruction binding. The user has been asked whether Editor Santander v2 should serve both editor and upscaler roles; the editor fixture currently records the requested Creador mapping rather than silently changing it.
- Editor has suitable inputs for editing: `foto_para_editar` is the source-image candidate and `quiero_editar` is the instruction candidate. The Art Director still confirms semantic bindings.
- Editor can receive an image for the requested upscaler role, but the schema provides no dimension or scale controls. Exact 3,840-pixel output is **unverified**. A fixed instruction may be configured on `quiero_editar`, but neither that instruction nor the app name proves the output contract. Gate B must measure actual output dimensions; no 4K capability is accepted from this schema alone.
- Every property contains `x-krea-wire-type`, which is absent from the plan's current `SchemaSubset::SUPPORTED_KEYS`. An unchanged Task 9 implementation would reject these real schemas. Preserve the original annotation in fixtures. Before activation support is implemented, explicitly handle this provider annotation as semantic metadata (`text`/`image`) without ignoring unrelated unsupported keywords or validation constraints. The existing `x-custom` rejection test must remain meaningful.
- The original Gate B matrix includes Skechers and Invierno variants not represented by these selected role schemas. Its exact live-run scope must be reconciled with the chosen versions before paid execution.

## Sanitization and limits of evidence

Only `name`, `node_app_version_id`, and `input_openapi_schema` were retained from each response. Other metadata, including example output URLs, was omitted. The in-memory sanitizer removes any literal configured key, bearer token, data URL, or URL query string before fixture persistence. The key was read through server-side Laravel configuration and was not printed or placed in command arguments, source files, snapshots, or queue payloads.

The fixtures establish schema access and input shape only. They do not establish image transport, provider job cardinality, output format, art-direction quality, cost, or 4K sizing; those require the separately approved Gate B run.
