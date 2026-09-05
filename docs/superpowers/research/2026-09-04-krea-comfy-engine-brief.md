# Media Ops — Krea and Comfy engine research brief

**Status:** Documentation/prototype research completed on 2026-09-04 using two research subagents and lead reconciliation. See [Comfy findings](2026-09-04-comfy-api-findings.md), [workflow fit](2026-09-04-engine-workflow-fit.md), and the [recommendation and exact design changes](2026-09-04-engine-recommendation.md). Recommendation: Krea primary for slice 1; Comfy deferred as a per-pipeline option. Engine choice and live experiments are not approved by this research. No implementation or paid operation was performed.
**Date:** 2026-09-04
**Purpose:** Decide whether Comfy API should be the primary or secondary image engine for Media Ops, and determine the smallest integration boundary that can support the selected engines.

## Read first

- [Current design](../specs/2026-09-04-muse-media-ops-slice-1-design.md). Its Krea-specific engineering sections are a working candidate and must be revised after this research.
- [Design review](../specs/2026-09-04-muse-media-ops-slice-1-review.md). Some corrections have been drafted; no application exists and no implementation plan is complete.
- The source prototypes under `prototypes/`, especially the full canvas source and `uploads/fotos-skechers.html` / `uploads/generador-invierno.html`. Inspect source without using or printing embedded credentials.

## Settled product decisions

- Laravel/Filament app with a studio Art Director panel and a brand-scoped Editor panel; Spanish UI and standard Filament layouts.
- Campaigns have no statuses. Each can configure generator, editor, and upscaler workflows.
- Generator forms expose configured inputs. Editing asks for the current image and an instruction; extra settings are managed by Art Directors.
- 4K means 3,840 pixels on the longest edge with preserved aspect ratio and validated output dimensions.
- Shared curation marks; temporary table selection for ZIP downloads.
- Copy every generated result into private studio-controlled object storage.
- Recover existing jobs first. A new potentially billable execution requires the Editor's confirmation modal; do not assume an automatic secondary-engine retry is authorized by that policy.
- Visual redesign, quotas, curated product library, and campaign status workflows are outside this slice.

## Initial evidence, not a feasibility conclusion

Comfy's product page describes packaging a workflow and environment with Builder, including models, custom nodes, and dependencies, then deploying an autoscaling API. It labels the offering beta. [Comfy API](https://comfy.org/platform/comfy-api)

The developer overview distinguishes Comfy Cloud, Developer Platform deployments, and self-hosted ComfyUI. It describes SDKs and an HTTP API, with deployment-specific targets. Research must identify which product and API the proposed integration actually uses. [Comfy developer overview](https://docs.comfy.org/development/overview)

Do not infer production suitability, pricing, workflow equivalence, or retry guarantees from these overview pages.

## Recommended parallel research

Use two subagents with independent scopes. The lead agent owns reconciliation and the final recommendation; neither subagent modifies the design or implementation files.

### Agent A: Comfy API contract and operation

Research the hosted Developer Platform product linked by the user, distinguishing it from Comfy Cloud and self-hosting. Use current official documentation and source repositories. Produce a source-linked report covering:

1. Account/access requirements, beta limitations, authentication, and workspace/team boundaries.
2. Workflow/environment packaging, version pinning, deployment changes, custom nodes/models, and who maintains those dependencies.
3. Real request/response examples: input discovery/schema, image upload, execution, job IDs, status, cancellation, output retrieval, and errors. Determine whether Laravel can integrate through HTTP without an additional application runtime.
4. Recovery after client timeout or worker restart; idempotency or job lookup guarantees, if documented. Distinguish documented behavior from assumptions.
5. Deployment/startup time, concurrency, quotas, rate limits, and current pricing components, including idle/minimum charges and storage/egress where applicable. Do not invent a per-image price without a specified workflow and measured runtime.
6. Data retention, output availability, private assets, and terms relevant to the studio's commercial use. Cite facts and flag questions for the provider rather than making unsupported assurances.

Save findings to `docs/superpowers/research/2026-09-04-comfy-api-findings.md`.

### Agent B: Workflow fit and Krea baseline

Independently review current official Krea documentation and the local prototype input/output contracts. Produce:

1. A baseline for Krea: workflow schema/versioning, credentials, job cardinality/status/recovery, image transport, outputs, and current pricing units.
2. Requirements extracted from the supplied workflows: text-to-image; Skechers' three image inputs; Invierno's image plus four text inputs; image + instruction editing; aspect-preserving 4K delivery. Separate UI payload evidence from unavailable actual node graphs/models.
3. What can be ported as product behavior versus what needs new Comfy workflows, model selection, and art-direction validation. Do not claim workflow equivalence merely because both tools use nodes.
4. An acceptance matrix for functional and visual quality comparison, and the missing workflow/account assets required to test it.

Save findings to `docs/superpowers/research/2026-09-04-engine-workflow-fit.md`.

### Lead agent: Comparison and recommendation

Review both reports and resolve contradictory or stale evidence using primary sources. Compare Krea-only, Comfy-only, and per-pipeline engine selection across functional coverage, art-direction reproducibility, configuration effort, latency, cost, recovery, and operational burden. Prefer the smallest workable solution; two engines need not both be implemented in slice 1.

Recommend primary/secondary/deferred status for each engine, with confidence and the evidence still needed. Define what "secondary" means: choosing the engine per pipeline is distinct from automatic failure fallback. Automatic fallback needs separate product approval because it can alter output and duplicate work/charges.

Propose an adapter boundary based on actual API differences: workflow metadata/input mapping, submission handle(s), progress/recovery, outputs, and capability flags. Preserve provider-specific identifiers in execution snapshots; keep tenancy, generation history, pieces, selection, and exports provider-independent. Avoid forcing Comfy workflow JSON into Krea's schema format without an explicit mapping.

Save the recommendation and exact design changes to `docs/superpowers/research/2026-09-04-engine-recommendation.md`. Then revise the design/review and finish the implementation plan, or identify the specific missing evidence that prevents that decision.

## Scope of this research

Begin with documentation and local prototype inspection. No paid execution, workflow deployment, production changes, or reuse of prototype credentials is authorized by this brief. If a feasibility experiment is necessary, first specify the exact workflow, inputs, account needs, number of runs, and expected spending for user authorization. Mark cost/latency/quality as unmeasured until that experiment occurs.

Research outputs should distinguish **verified**, **inferred**, **unverified**, and **unsupported**, link claims to primary sources, and record retrieval dates. The result should enable a decision, not merely list platform features.

## Prompt for a fresh session

> Research Comfy API as a possible primary or secondary image engine for Media Ops. Read `docs/superpowers/research/2026-09-04-krea-comfy-engine-brief.md` and the linked design/review. Use two research subagents with the scopes in the brief, then synthesize the recommendation yourself. Start with read-only research; propose any paid experiment before running it. Recommend the smallest viable engine architecture and the changes needed before we finalize the implementation plan. Preserve all settled product decisions.
