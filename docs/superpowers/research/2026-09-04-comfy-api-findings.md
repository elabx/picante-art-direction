# Comfy API — hosted Developer Platform findings

**Retrieved:** 2026-09-04. **Scope:** Agent A of the [engine brief](2026-09-04-krea-comfy-engine-brief.md), read alongside the Slice 1 design and review. Public documentation/source research only; no authenticated API calls, builds, deployments, or generations were executed. HTTP examples below are illustrative compositions of documented fields, not captured responses or tested workflows.

**Evidence labels:** **Verified** means supported by retrieved official documentation/source, not independently runtime-tested. **Inferred** means an integration consequence. **Unverified** means evidence or provider confirmation is missing. **Unsupported** means the stated capability is excluded or contradicted by the retrieved contract.

## Decision-bearing findings

- **Verified:** The hosted service has a documented HTTP API usable from Laravel. Its API-format graph and asset references differ materially from a Krea named-input schema. A Python/Node application sidecar is unnecessary for ordinary submission and polling. [v2 overview](https://docs.comfy.org/api-reference/v2/overview)
- **Verified:** Submission deduplication is **single-use key rejection**, not replay of a lost response. A lost job ID remains a material recovery gap; “idempotent” must not be translated into exactly-once execution or recoverable submission handles. See recovery below.
- **Inferred:** Comfy is technically plausible as a configured engine, but readiness requires authored workflows, release/deployment management, verified account access, and an approved feasibility experiment. The evidence does not establish visual equivalence, latency, cost per accepted image, or production reliability.
- **Product constraint:** Existing-job recovery remains first. Any new potentially billable execution needs the Editor's confirmation; automatic secondary-engine fallback is outside the settled policy.

## Product and account boundary

| Surface | Verified contract boundary |
| --- | --- |
| **Comfy API / Developer Platform** | Beta; independently deployed endpoint at `https://{deployment}.run.comfy.app`; custom environment and managed GPU workers. This is the product under evaluation. |
| **Comfy Cloud** | Shared managed editor/service at `https://cloud.comfy.org`; SDK default target. Its subscription tiers and Cloud-specific queue/model APIs are separate. |
| **Self-hosted ComfyUI** | User-operated infrastructure. During v2 beta, the optional `comfy-api-proxy` supplies v2; raw `/prompt`, `/history`, and WebSocket routes are another interface. |

Sources: [product page](https://comfy.org/platform/comfy-api), [developer overview](https://docs.comfy.org/development/overview), [deployment/API surfaces](https://docs.comfy.org/api-reference/v2/overview). The overview promises additive v2 changes, while SDK documentation still describes a `0.1.x` beta. Treat this as an evolving documented interface, not evidence of an SLA.

**Verified:** Keys are created in the logged-in Developer Platform; their secret is shown once, multiple application keys can exist, and compromised keys can be deleted. [API keys](https://docs.comfy.org/development/api-development/getting-an-api-key) Hosted request authorization uses `Authorization: Bearer <api-key>` and is account-scoped. [asset authorization](https://docs.comfy.org/api-reference/v2/assets/delete-an-asset-record)

**Verified:** Current official CLI guidance requires a usable login for Builder operations reaching the service and reports `build_not_enabled` for accounts outside the limited beta. **Unverified:** This studio's enablement, minimum credit purchase, Developer Platform subscription requirement, team roles, service-account support, key scopes, cross-member asset access, and key-rotation behavior. Cloud's paid-subscription requirement must not be silently applied to this product. [Builder reference](https://raw.githubusercontent.com/Comfy-Org/comfy-cli/main/comfy_cli/skills/comfy-build/SKILL.md), [SDK target distinctions](https://docs.comfy.org/development/api-development/sdks)

**Inferred:** Keep the Media Ops brand boundary in Laravel. A shared studio Comfy account does not make provider-side assets brand-isolated. Snapshot the credential source/account association without copying its secret; verify continuing access to existing jobs after rotation.

## Environment, reproducibility, and operating ownership

**Verified:** Builder keeps an editable `comfy-build.yaml`; releases are immutable. The current CLI can scan an install, import a Desktop snapshot, or resolve a workflow. Workflow-only import leaves models unresolved and selects the newest published registry pack versions; it also needs a ComfyUI version. Re-importing later can select different dependencies. Local scanning captures package state, but indiscriminately transferring a macOS pip freeze into Linux can break the build. [Builder reference](https://raw.githubusercontent.com/Comfy-Org/comfy-cli/main/comfy_cli/skills/comfy-build/SKILL.md)

**Verified:** The authoring schema supports `baseComfyVersion`, catalog `baseImage`, model `sourceUri` or private `blobId`, model SHA-256, custom-node registry versions/Git references/blobs, and dependency overrides. Mutable branches resolve when the release is cut; pin commits for repeatability. The recorded source `environment` does not select the build environment. Model/custom-node/partner-node policy fields are manifest records, **not enforced allowlists**. Gated model downloads need uploaded blobs when anonymous Builder fetching cannot access them. [authoring reference](https://raw.githubusercontent.com/Comfy-Org/comfy-cli/main/comfy_cli/skills/comfy-build-authoring/SKILL.md)

**Verified:** A deployment pins one release. Deploying a newer release creates another endpoint; the previous deployment remains until retired. CLI worker bounds accept minimum 0–20 and maximum 1–20, supplied together; these are client bounds, not a promised account capacity. Changing GPU/region requires a stopped deployment. [deployment CLI source](https://raw.githubusercontent.com/Comfy-Org/comfy-cli/main/comfy_cli/command/deploy.py), [deployment reference](https://raw.githubusercontent.com/Comfy-Org/comfy-cli/main/comfy_cli/skills/comfy-deploy/SKILL.md)

**Inferred:** The studio owns model selection, licenses, custom-node provenance, dependency upgrades, test images, and visual acceptance. Persist graph/hash, build/release/deployment IDs, endpoint, mappings, and resolved inputs per execution. A release change is an operational rollout: switch only new requests, retain access needed by old jobs, then retire old resources. Builder packaging does not eliminate this maintenance work.

**Unverified documentation discrepancy:** The overview calls a serverless deployment “one pinned workflow,” while the deployment guide/CLI submit an API-format graph per request into a release-bound environment. No standalone `GET /workflow` exists in the inspected v2 paths: workflow retrieval is job-scoped. Do not assume a deployment exposes a fixed named-input form or permits arbitrary graph substitutions until tested. [overview](https://docs.comfy.org/api-reference/v2/overview), [serverless guide](https://docs.comfy.org/development/serverless/overview)

## HTTP integration contract

### Input discovery and execution mapping

**Unsupported in current v2:** Saved-workflow management, node introspection, and named workflow parameters. **Verified:** API workflow JSON uses node IDs, `class_type`, and `inputs`; it differs from the editor's `nodes`/`links` save file. [SDK scope](https://docs.comfy.org/development/api-development/sdks), [workflow format](https://docs.comfy.org/development/api-development/workflow-api-format)

**Inferred:** Import a reviewed graph plus an explicit application form manifest mapping Media Ops fields to `node_id`/input-name destinations. Treat semantic image/text roles as configuration; an entire graph is not an OpenAPI form schema. Keep the Editor's image-plus-instruction edit flow and image-only upscale flow, with other values supplied by Art Directors.

### Upload

**Verified:** `POST /api/v2/assets` uses multipart fields `file`, `content_type`, and `file_path`; returns 201 for a new blob or 200 for deduplicated bytes with an asset record. Optional `expected_hash` is a verified BLAKE3 digest. The documented single-request expectation is approximately 100 MB, not a hard SLA. Optional `expires_in` accepts 60–604800 seconds, but implementations without configurable retention may ignore it. [upload endpoint](https://docs.comfy.org/api-reference/v2/assets/upload-an-asset-single-call-multipart)

```http
POST /api/v2/assets
Host: <deployment>.run.comfy.app
Authorization: Bearer <server-held-key>
Content-Type: multipart/form-data; boundary=mediaops

--mediaops
Content-Disposition: form-data; name="content_type"

image/png
--mediaops
Content-Disposition: form-data; name="file_path"

input.png
--mediaops
Content-Disposition: form-data; name="file"; filename="input.png"
Content-Type: image/png

<binary image bytes>
--mediaops--
```

**Verified:** Uploaded asset IDs replace filenames in graph inputs as `{"__type":"core/ASSET","info":{"id":"<asset-uuid>"}}`; `info.id` is authoritative. The caller must own referenced assets. [asset-reference schema](https://raw.githubusercontent.com/Comfy-Org/docs/main/openapi-v2.yaml)

### Submit and receive a job

**Verified:** `POST /api/v2/jobs` receives `workflow`, validates synchronously, and returns one durable queued Job on 201. Top-level named `inputs` and `webhook_url` are reserved and rejected. Optional `extra_data.api_key_comfy_org` supplies Partner Node credentials; inject secrets at dispatch rather than persisting them in graph snapshots. [submission endpoint](https://docs.comfy.org/api-reference/v2/jobs/submit-a-workflow-for-execution)

```http
POST /api/v2/jobs
Host: <deployment>.run.comfy.app
Authorization: Bearer <server-held-key>
Idempotency-Key: <persisted-uuid>
Content-Type: application/json

{"workflow":{"10":{"class_type":"LoadImage","inputs":{"image":{"__type":"core/ASSET","info":{"id":"<asset-uuid>"}}}},"11":{"class_type":"SaveImage","inputs":{"images":["10",0],"filename_prefix":"mediaops"}}}}
```

This minimal upload/save graph illustrates transport only; it generates no new artwork and is not evidence of Skechers, Invierno, editing, or upscaling fit.

### Poll, outputs, errors, and cancellation

**Verified:** `GET /api/v2/jobs/{id}` is authoritative. States are `queued`, `running`, `succeeded`, `canceling`, `canceled`, `failed`, `expired`; terminal states are `succeeded`, `canceled`, `failed`, and `expired`. Results accumulate in `outputs`; execution errors live in `job.error`. Persist the actual returned `expires_at`. Example projection (other required Job fields omitted): [job endpoint](https://docs.comfy.org/api-reference/v2/jobs/job-status-the-polling-workhorse)

```json
{
  "id": "<job-id>",
  "status": "succeeded",
  "expires_at": "<provider-deadline>",
  "outputs": [{"id":"<asset-id>","node_id":"11","name":"result.png","type":"image","content_type":"image/png","size_bytes":12345,"hash":null,"url":"<signed-url>","url_expires_at":"<url-deadline>"}],
  "error": null,
  "urls": {"self":"/api/v2/jobs/<job-id>","events":"/api/v2/jobs/<job-id>/events","cancel":"/api/v2/jobs/<job-id>/cancel"}
}
```

**Verified:** `GET /api/v2/assets/{id}` returns metadata and refreshed download URLs; asset `expires_at` differs from `url_expires_at`, and absent/null asset expiry means non-expiring. Deduplication can extend retention. [asset metadata](https://docs.comfy.org/api-reference/v2/assets/asset-metadata) `GET /api/v2/assets/{id}/content` returns a **302** signed-URL redirect on hosted surfaces; byte ranges are supported. [asset bytes](https://docs.comfy.org/api-reference/v2/assets/asset-bytes)

**Inferred:** Persist output identity by provider asset ID/node/name, not URL hash; URLs rotate. Use authenticated provider metadata retrieval followed by a separate bounded download of the signed URL without the API credential. Validate permitted hosts/redirects, decoded type and dimensions before private storage. The existing Krea no-redirect policy cannot describe both providers. Keep every output manifest entry, handle unsupported outputs explicitly, and badge 4K only after the settled 3840-longest-edge validation.

**Verified:** HTTP failures use `{"error":{"code":"...","message":"...","details":{...}}}`. Submission includes 401/403 auth, 402 `insufficient_credits`, 422 workflow/asset/key errors, and 429 `queue_full` or `deployment_not_ready`; read the code and `Retry-After`, not status alone. [submission endpoint](https://docs.comfy.org/api-reference/v2/jobs/submit-a-workflow-for-execution) Polling supports rate-limit/backoff handling. [job endpoint](https://docs.comfy.org/api-reference/v2/jobs/job-status-the-polling-workhorse)

**Verified:** `POST /api/v2/jobs/{id}/cancel` is idempotent and returns current state, including `canceling` or an already terminal result. Interruption happens at node/step boundaries; consumed GPU time remains billed. Cancellation is not proof the work immediately stopped or a refund. [cancel endpoint](https://docs.comfy.org/api-reference/v2/jobs/request-cancellation)

## Recovery: the critical limitation

**Verified:** `Idempotency-Key` is optional/recommended, single-use, and expires after 24 hours. Reuse returns 422 `idempotency_key_reuse`, including changed-body duplicates; no previous response or job ID is replayed. Definitive no-job rejection releases the key; an uncertain upstream outcome holds it. Exact key namespace across account/deployment/endpoint is **unverified**. [canonical OpenAPI](https://raw.githubusercontent.com/Comfy-Org/docs/main/openapi-v2.yaml)

**Verified:** The official deployment reference explicitly says there is no job-list endpoint, lookup by idempotency key, or caller-supplied job ID. Deployment activity cannot identify the missing job. This contradicts the submission prose's suggestion to list jobs. [deployment reference](https://raw.githubusercontent.com/Comfy-Org/comfy-cli/main/comfy_cli/skills/comfy-deploy/SKILL.md)

**Inferred policy:** Persist the attempt/key before sending; save the returned handle immediately. If known, recover only by GET and asset retrieval. If unknown, retain `submission_unknown`; do not automatically POST again. If the original never arrived, a same-key POST can start the first billable execution; after key expiry it can duplicate earlier work. Neither case is automatically free recovery. Explicit Editor confirmation governs a replacement with a fresh attempt/key. Deduplication reduces one risk but does not replace the local submission claim or confirmation record.

**Verified:** SSE is optional live progress without replay IDs/cursors; polling is the recovery source. [design notes](https://docs.comfy.org/development/api-development/sdks-design) **Unverified:** Job access after deployment stop/delete and exact retention guarantees across failed workers require tests/provider confirmation before retirement procedures are fixed.

## Pricing, startup, and capacity

**Verified published USD rates**, billed by GPU second; availability must be checked in the live compute catalog. [Developer Platform resource pricing](https://comfy.org/pricing)

| Resource | Published price |
| --- | --- |
| RTX PRO 6000 / 96 GB | $3.49 per GPU-hour |
| H100 / 80 GB | $4.79 per GPU-hour |
| H200 / 141 GB | $5.93 per GPU-hour |
| B200 / 180 GB | $8.64 per GPU-hour |
| Standard network storage below 1 TB | $0.091/GB-month |
| Standard network storage at/above 1 TB | $0.065/GB-month |
| High-performance network storage | $0.182/GB-month |
| Container disk | $0.13/GB-month |

**Verified:** Minimum workers are billed while running, even idle. Flex billing includes startup/model loading, processing, and currently 30 seconds of trailing idle; `min=0` eliminates idle compute. Undeployed Build/release storage is free. Deployed model storage is billed per region even when paused; it is cleaned up shortly after the last relevant deployment is deleted. Each running worker has 50 GB ephemeral disk. Credit exhaustion stops deployments. [serverless billing guide](https://docs.comfy.org/development/serverless/overview)

**Unverified:** The guide describes disk within worker compute cost whereas pricing calls it separately billed; confirm accounting. The Builder reference mentions billable build minutes without a published rate found here. No fixed release/startup duration, requests-per-second limit, queue depth, per-worker concurrent-job guarantee, maximum job runtime, SLA, egress price, or input/output storage price was established for Developer Platform. Cloud's 1/3/5 concurrency and subscription runtime limits do not fill these gaps.

**Inferred:** Budget using total worker seconds plus storage, build and Partner Node charges, not a per-image guess. A warm RTX worker alone would cost about $2512.80 over 720 hours. Cold-start and quality measurements remain absent.

## Private data and commercial use

**Verified:** General customer-content retention is purpose-dependent, not a universal 24-hour deletion promise. The separate 24-hour statement concerns Partner Node media. [data retention](https://docs.comfy.org/support/data-retention) Deleting an asset deletes its record; shared underlying bytes may remain while referenced elsewhere. [asset deletion](https://docs.comfy.org/api-reference/v2/assets/delete-an-asset-record)

**Verified:** Terms dated May 13, 2026 retain customer rights to inputs/outputs as between customer and Comfy, prohibit using them to train generative/diffusion models, permit limited derived metadata use, and apply third-party terms/data practices to Partner Nodes. Access rights say internal business purposes; third-party sublicensing is restricted. **Unverified:** Whether the studio's brand-client Editor access is expressly covered by its order form—obtain provider confirmation rather than infer unrestricted SaaS resale rights. Model/node licenses require separate review once selected. The terms also reserve service/API changes; documentary v2 stability language is not a contractual uptime assurance. [terms](https://comfy.org/terms-of-service)

**Inferred:** Copy results promptly into studio-controlled private storage. Review embedded workflow/prompt metadata in delivered images: ComfyUI can embed recipe information in output files. [workflow metadata](https://docs.comfy.org/development/api-development/workflow-metadata) **Unverified:** Data residency/subprocessors, private blob isolation guarantees, deletion/backup timelines, asset retention override support, and any required DPA must be confirmed for actual client assets.

## Proposed experiment — not authorized or executed

The lead's [staged experiment proposal](2026-09-04-engine-recommendation.md#gate-c-optional-comfy-transport-probe-then-a-separately-scoped-visual-port) is the reconciled scope. It proposes one core-only resize/transport graph, one release/deployment, five new jobs, and one within-window duplicate-key check. It does not test AI enhancement quality or port the creative workflows. A very short graph may also be insufficient to observe cancellation while running; do not claim that coverage from a terminal-job cancellation response.

Before any experiment, obtain beta access, exact account/region prices, the release manifest, and answers about unknown-job recovery, retention, and billing. The lead report gives conditional GPU/storage components and a proposed budget ceiling, explicitly excluding any claim of a complete quote while build/transfer accounting remains unknown. No spending is authorized by either report.

A subsequent visual comparison requires separately approved graphs, model/node identities and licenses, matched input cases, repeat counts, and a total price. Capture sanitized job/output fixtures, cold/warm timings, actual charges, accepted-image rate, and stored dimensions. Verify retirement accounting only after outputs are copied and recovery needs are resolved.
