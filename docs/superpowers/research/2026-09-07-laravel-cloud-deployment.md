# Laravel Cloud deployment assumption

The user proposed Laravel Cloud hosting on 2026-09-07. Use it as the target for the deployment documentation in Tasks 24–25; application development continues locally through DDEV.

## Compatibility and proposed setup

- Laravel Cloud supports PHP 8.5 and Laravel 12. Use the repository's `app/` directory as its application root. Cloud supports selecting a subdirectory for build and runtime operations.
- Use managed MySQL and Redis-compatible storage. Run the existing Redis queue through a dedicated worker cluster with `php artisan queue:work redis --timeout=150 --tries=1`. Preserve the application's 420-second retry interval and job timeouts of 90/60/120 seconds.
- Do not select managed Flex queues for this configuration: their documented maximum job runtime is 90 seconds, below the planned 120-second download budget. A future managed Pro queue migration is possible but requires deliberate queue configuration and verification; it is not necessary to adopt Cloud hosting.
- Enable Cloud's Laravel scheduler when deployment is configured. Verify reconciliation and retention schedules in Task 25, including behavior across replicas and deployments.
- Keep private S3 storage and CloudFront signed image URLs as approved. Cloud hosting does not require replacing this storage design.
- Build assets and run dependency installation in the application root. Gate deployment on passing CI checks, then deploy the tested commit through Cloud's deployment hook. Keep preview/staging and production credentials and data separate; preview environments use the fake engine.

These are repository planning assumptions, not provisioned resources or verified remote deployment results. Task 24 must verify PHP extensions, queue shutdown behavior, memory budgets for image decoding, environment configuration, and private image delivery on the selected environment before calling production wiring complete.

## Sources checked

- [Laravel Cloud introduction and supported versions](https://laravel.com/cloud/docs/intro)
- [Application subdirectories](https://laravel.com/cloud/docs/monorepos)
- [Queues: worker clusters, Flex runtime limits, and connection selection](https://laravel.com/cloud/docs/queues)
- [Scheduled tasks](https://laravel.com/cloud/docs/scheduled-tasks)
- [Deployment hooks and release behavior](https://laravel.com/cloud/docs/deployments)
