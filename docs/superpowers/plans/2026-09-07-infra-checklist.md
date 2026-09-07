# Production storage and queue checklist — 2026-09-07

Target: Laravel Cloud compute with private AWS S3 and CloudFront. **No remote resources or staging exist for this task; every remote check below is PENDING.** These are owner-run instructions, not deployment results. Local evidence appears below. Placeholder settings: [`app/.env.production.example`](../../../app/.env.production.example).

## AWS owner checks — PENDING

Use your authenticated AWS console/CLI and substitute resource IDs locally. Never include credentials or signed URLs in reports.

| Requirement | One-line verification |
| --- | --- |
| Private bucket; all four Block Public Access settings enabled; no public policy or ACL | S3 console → bucket → Permissions: all blocks on, no public grants in policy or ACL. |
| CloudFront S3 REST origin with Origin Access Control (OAC), signing always; bucket policy grants `cloudfront.amazonaws.com` `s3:GetObject` only for this distribution's `AWS:SourceArn` | CloudFront → Origins: OAC signing always; compare distribution ARN with the S3 policy condition. |
| Viewer access restricted on both `inputs/*` and `pieces/*` using the trusted key group | CloudFront → Behaviors: every media behavior restricts viewer access and selects the key group. |
| Key group includes public key matching the PEM; `CLOUDFRONT_KEY_PAIR_ID` is the **public key ID**, not key group ID | CloudFront → Public keys / Key groups: compare ID and public-key fingerprint with app owner's key. |
| Bucket CORS permits `GET`, `HEAD`, **`PUT`** from exact HTTPS app origin, requested upload headers, and exposes `ETag` / `Content-Disposition` | `aws s3api get-bucket-cors --bucket "$BUCKET"` |
| CloudFront response headers policy preserves origin `Content-Disposition` and exposes it through CORS; no static filename override/removal | CloudFront → behavior → Response headers policy: expose `Content-Disposition`, no custom override/removal of it. |
| Cache policy includes `response-content-disposition` query parameter, forwarding it to S3 and separating preview/download cache entries | CloudFront → behavior → Cache policy: query allowlist includes `response-content-disposition`; verify warm-cache preview and differently named downloads. |
| Lifecycle expires `inputs/tmp/` after 1 day; preserves finalized inputs and pieces | `aws s3api get-bucket-lifecycle-configuration --bucket "$BUCKET"` |
| Dedicated app IAM user in the **same AWS account as the bucket**: `s3:ListBucket` on `arn:aws:s3:::BUCKET`; `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject` on `arn:aws:s3:::BUCKET/*`; limited private-ACL permission below | IAM → app user → Permissions: inspect all inline, attached/group policies; compare user and bucket account IDs. |
| Object Ownership **Bucket owner preferred**, all app writes private; encryption SSE-S3 | S3 → Permissions → Object Ownership: Bucket owner preferred; Properties: SSE-S3; staging upload succeeds and owner/account matches bucket. |
| Additional `s3:PutObjectAcl` only on this bucket's objects and only with `s3:x-amz-acl=private` | IAM → app policy: exact action/resource/condition below; no `PutBucketAcl` or other ACL grants. |
| HTTPS-only private media delivery | Private browser window → known direct S3 and unsigned CloudFront object: both 403; authorized app media route: 200 image. |

The brief's GET-only CORS instruction was insufficient: Livewire uses a signed browser PUT directly to S3. The disk root `inputs` and temporary directory `tmp` produce lifecycle prefix `inputs/tmp/`. [Livewire 4 S3 uploads](https://livewire.laravel.com/docs/4.x/uploads).

S3 accepts `response-content-disposition` for attachment responses. A response headers policy alone neither forwards that query nor separates cached responses; include it in the cache policy. Test preview, download, then preview again against a warm cache. [S3 GetObject overrides](https://docs.aws.amazon.com/AmazonS3/latest/API/API_GetObject.html), [CloudFront query caching](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/QueryStringParameters.html).

**Approved compatibility correction to the brief:** installed Livewire and Flysystem explicitly send a `private` ACL. AWS's default Bucket owner enforced rejects it. Use **Bucket owner preferred** with the app user in the bucket's own AWS account; private objects remain owned by that account. Keep all public-access blocks enabled and OAC read access restricted to the distribution. This retains ACL support instead of AWS's preferred ACL-disabled model to accommodate the installed upload clients. An ACL-free application adaptation would be a separate change. [S3 Object Ownership](https://docs.aws.amazon.com/AmazonS3/latest/userguide/about-object-ownership.html), [CloudFront OAC ACL compatibility](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-restricting-access-to-s3.html).

AWS requires `s3:PutObjectAcl` when PUT includes an ACL. Add this **separate statement** to the four-action policy above; it allows only the existing private ACL, not arbitrary grants. No AWS change was applied here; verify uploads and OAC delivery in staging. SSE-S3 avoids adding unrelated KMS permissions. [S3 required API permissions](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-with-s3-policy-actions.html).

```json
{
  "Effect": "Allow",
  "Action": "s3:PutObjectAcl",
  "Resource": "arn:aws:s3:::REPLACE_WITH_BUCKET/*",
  "Condition": {"StringEquals": {"s3:x-amz-acl": "private"}}
}
```

## Laravel Cloud owner checks — PENDING

| Requirement | One-line verification |
| --- | --- |
| Application root `app/`, PHP 8.5, supported Node version matching asset build | Cloud → environment settings: root and runtimes; Commands: `php -v`. |
| Required extensions loaded, including GD JPEG/PNG/WebP decoding | Commands: `php -r 'foreach (["gd","fileinfo","exif","openssl","pcntl","pdo_mysql","redis","intl","mbstring","curl"] as $e) { echo $e.": ".(extension_loaded($e) ? "yes" : "NO").PHP_EOL; } echo json_encode(gd_info()).PHP_EOL;'` |
| Actual web/worker memory accommodates decoded images, 96 MiB aggregate inputs and 64 MiB output downloads at chosen concurrency | Commands: `php -r 'echo ini_get("memory_limit").PHP_EOL;'`; measure web/worker RSS with maximum supported fixtures and concurrent jobs in staging. |
| Stable `APP_KEY`, signing key, AWS and provider keys linked as secrets; debug off | Cloud → injected variables: inspect names/links only, `APP_DEBUG=false`, no blank custom overrides of linked secrets. |
| External AWS bucket/region selected; no Cloud object-storage endpoint/URL override | Cloud → variables: verify bucket/region, absent `AWS_ENDPOINT` / `AWS_URL`; app disk roots remain `inputs` / `pieces`. |
| Redis for queue, cache and session; separate environment credentials/prefixes shared across replicas | Commands: `php artisan tinker --execute='echo config("queue.default")." / ".config("cache.default")." / ".config("session.driver").PHP_EOL; echo Illuminate\Support\Facades\Redis::connection()->ping();'` |
| Dedicated **Worker cluster**, initially one process, command `php artisan queue:work redis --timeout=150 --tries=1` | Cloud → Worker cluster → Background processes: exact command/process count, no duplicate app-cluster worker. |
| Redis/database retry 420s exceeds worker 150s and Run/Poll/Download job 90/60/120s budgets | Commands: `php artisan tinker --execute='echo config("queue.connections.redis.retry_after")." / ".config("queue.connections.database.retry_after").PHP_EOL;'`; compare worker command and deployed job timeout properties. |
| Shutdown lets a 120s download finish; target drain grace at least 150s | Owner confirms platform termination grace, then deploys during a fixture-only long job and compares generation/output IDs and submit count; block production until confirmed. |
| Scheduler enabled on one chosen cluster; shared Redis locks verified | Commands: `php artisan schedule:list`; Cloud → scheduler enabled on selected cluster only; observe one reconciliation per minute. |
| Preview/staging isolated from production resources/secrets | Cloud → each environment → compare DB, Redis prefixes, bucket and linked secret IDs. |

Cloud supports these extensions, but deployed checks remain mandatory. Memory varies with instance size; local evidence below is not production sizing. Put optimization in build commands. Deploy-command filesystem writes do not persist. Workers restart automatically; do not add `queue:restart`, `optimize:clear`, `storage:link`, or a deploy-created PEM file. [Cloud runtime/environment documentation](https://laravel.com/cloud/docs/environments).

Link `CLOUDFRONT_PRIVATE_KEY_BASE64` as a Cloud secret containing base64 of the unencrypted PEM. The app decodes it in memory, preferring it over `CLOUDFRONT_PRIVATE_KEY_PATH`; invalid content fails closed with a generic error. The path alternative requires a readable file on every replica. Cloud documents ENV injection, not persistent secret-file mounts. Redeploy after rotation and never dump `media` config or config-cache files. [Cloud secrets](https://laravel.com/cloud/docs/secrets).

Preserve Redis semantics. Current Cloud docs give managed Flex **90 seconds of shutdown grace** and recommend Pro for longer jobs; they do not impose a fixed runtime cap. This corrects the earlier research interpretation. Our baseline remains a dedicated Worker cluster using Redis. Confirm its own termination behavior instead of assuming managed-queue grace applies; keep worker compute available while jobs are pending. [Cloud queues and Worker clusters](https://laravel.com/cloud/docs/queues).

The current schedule has `media:reconcile` every minute with `withoutOverlapping()`, but no `onOneServer()`. Until Task 25 verifies replica locks and adds retention scheduling, use one scheduler replica. Multiple scheduler replicas are a release blocker. [Cloud scheduler replica guidance](https://laravel.com/cloud/docs/scheduled-tasks).

### Build/release procedure — not configured yet

Set root to `app/`; Cloud runs these commands there. [Cloud monorepos](https://laravel.com/cloud/docs/monorepos).

Build:

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

Deploy:

```sh
php artisan migrate --force
```

Existing Composer post-autoload scripts run `package:discover` and `filament:upgrade`; allow them to finish in the application root. `filament/blueprint` and `laravel/boost` are dev dependencies and can be omitted in production. CI installing dev dependencies needs private Composer authentication from CI secrets, without copying or printing ignored local `auth.json`.

**CI and the Cloud deployment hook are proposed, not installed.** Configure CI to install lockfiles, run Pest, Pint and the asset build against MySQL with fake storage/providers, then invoke a protected Cloud hook only for the tested commit. Disable independent deploy-on-push paths that bypass CI; verify the deployed SHA equals the passing run. [Cloud deployment controls](https://laravel.com/cloud/docs/deployments).

`FAKE_ENGINE=true` binds the existing demo only under `APP_ENV=local`; local UI qualification also needs a DDEV worker restart. It does not enable fixtures in `staging` or `production`. Keep Cloud previews free of Krea credentials and generation unavailable until a scoped fixture runtime is implemented and reviewed. Never set a public preview to `APP_ENV=local` to bypass the guard. Fake output does not qualify real Krea apps; live testing remains deferred.

## Local MinIO evidence — server flow PASS, browser pending

Executed 2026-09-07 through DDEV: PHP 8.5.7, Laravel 12.69.1, Filament 5.7.8 / Livewire 4. Runtime storage configuration was consumed without printing keys. The ignored harness uses `muse_test` transactions, unique objects, `FakeEngine`, fake queues and blocked Laravel HTTP strays. It replaces Livewire's test-only signing stub with the real signer and maps the test temporary disk to actual MinIO.

Command from repository root (local ignored harness, not part of the portable suite):

```sh
ddev exec -d /var/www/html/app php -d memory_limit=256M vendor/bin/phpunit -c phpunit.xml /var/www/html/.superpowers/sdd/2026-09-05-muse-media-ops-slice-1/Task24MinioTest.php
```

Result: **1 test, 36 assertions, 2.400 seconds**, peak PHP allocation **65,028,096 bytes** under `256M`. The preceding default run had CLI `memory_limit=-1`; only the explicit-budget run proves the finite limit.

| Check | Observed result |
| --- | --- |
| In-memory GD fixture | Valid 2500×2000 PNG, exactly **15,728,640 bytes (15 MiB)**; uncompressed GD PNG with valid padding text chunk. |
| Generator `_startUpload`, actual signed MinIO PUT | 200; temporary object size 15,728,640 bytes. |
| `_finishUpload`, Generator `generate` | Validation succeeds; one pending generation references owned upload ID; final object at `inputs/{brand}/{uuid}.png`. |
| Finalization | Finalized SHA-256 equals fixture; original temporary object absent. |
| Component preview and `media.upload` | Component returns authorized media route; redirect followed over HTTP yields 200 `image/png`, identical hash. |
| Stored piece via `media.piece?download=1` | Actual HTTP 200, identical hash; `Content-Disposition: attachment; filename="pieza-2-2500x2000.png"`. |
| Revoked membership | Subsequent `media.piece` returns 403. |
| Isolation/cleanup | No provider submissions; queued job faked; exact created objects deleted, absence asserted; database transaction rolled back. |

This executes Livewire server actions and storage HTTP transfers, not browser CORS enforcement, rendered pixels, native downloads or JavaScript expiry behavior. CUA startup repeatedly failed in preceding tasks; no new browser retry was attempted. **Manual local checks remain PENDING:** with the local fake engine, select a GD-generated 15 MiB fixture in Generator, observe preview rendering, inspect final/temp objects in MinIO and save the attachment through the browser. A local fixture file for manual selection is permitted only as a test fixture, not application storage.

## Staging acceptance — PENDING (environment absent)

- [ ] Open a stored piece as an Editor; confirm authorized CloudFront delivery and 600-second expiry.
- [ ] Wait **11 actual minutes**; force the expired image to reload with browser cache disabled; confirm `onerror` obtains a fresh `media.piece` redirect and renders the image. Record statuses/timing, not signed URLs.
- [ ] Download after expiry; verify fresh authorization, 200 image bytes and expected attachment filename.
- [ ] Warm CloudFront cache in preview/download order and reverse order; ensure dispositions and different filenames never bleed between requests.
- [ ] Revoke the Editor's brand; `media.piece` and download variants must return 403. Previously issued storage URLs retain their existing lifetime; the app must issue no new one.
- [ ] Repeat the 15 MiB upload in a browser against AWS; check CORS, finalized object, removed temporary object and preview pixels.
- [ ] Complete runtime/memory, worker shutdown, scheduler/retention, CI SHA and key rotation checks above. Qualify real Krea separately after explicit live-test authorization.
