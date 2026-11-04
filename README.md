# FaultNest

Lightweight, privacy-first Laravel error monitoring with customizable grouping, local storage, and notifications.

> **Development status: capture + host Cache/Redis grouping + opt-in queued notifications. Not a complete V1 release.**
> Database history/readers, browser/React SDK, real concurrency tests and full Laravel/PHP compatibility integration testing remain pending. No release tag or Packagist publication is available.

## Principles

- **Your application is the server.** No FaultNest API, SaaS backend, UI, telemetry endpoint, or hosted database.
- **No automatic enrichment.** Only the exception type/message/location/trimmed stack, severity, time and configured project/environment. FaultNest never reads Laravel Auth, Request, session, IP, route, headers, or job payload for additional context.
- Optional context/tags/breadcrumbs are supplied **only by application code**. JSON-compatible values only, with size, depth and sensitive-key limits. Avoid submitting secrets. Exception messages themselves may contain sensitive details; built-in redaction is best effort, not a guarantee.
- No external traffic by default. Mail/custom channels are active only when explicitly configured and enabled, using the host application's own queue/transports.
- No HTTP routes or UI. Later local PHP reader services will let the host build its own UI using its own storage and authorization.
- PHP >=8.2, Laravel 12/13 target. Cross-version compatibility remains to be validated.

## Planned installation and setup

Install the Composer package in a Laravel app (via VCS/path repository until published), then publish defaults:

```sh
php artisan vendor:publish --tag=faultnest-config
```

In the host application's `.env`:

```dotenv
FAULTNEST_ENABLED=true
FAULTNEST_PROJECT=my-project
# Set only if you want email notification delivery:
FAULTNEST_NOTIFICATIONS_ENABLED=true
FAULTNEST_MAIL_ENABLED=true
FAULTNEST_MAIL_TO=developer@example.com,admin@example.com
```

In `app/Providers/AppServiceProvider.php`:

```php
use FaultNest\Laravel\Facades\FaultNest;

public function boot(): void
{
    FaultNest::init();
}
```

The discovered service provider only registers services. `init()` explicitly activates Laravel's non-stopping exception reporter (when enabled); Laravel's normal logging and exception rendering remain unchanged. Repeated `init()` calls do not register multiple reporters.

**Current milestone:** with `FAULTNEST_ENABLED=true`, group counters are stored in the host's configured Cache/Redis store by default. `CaptureResult::Captured` means processing succeeded, **not** that mail was delivered. No raw event history is stored yet. If disabled, FaultNest does not write or send anything. A custom `EventSinkInterface` may be bound to augment normal storage/notifications.

**Mail requires a running asynchronous Laravel Queue worker or Horizon**. Configure an appropriate host `QUEUE_CONNECTION` (e.g., `redis` or `database`) and the application's existing `MAIL_*` settings. The package intentionally refuses the `sync` / deferred queue drivers for delivery unless a channel explicitly sets `allow_sync=true`. This prevents accidental synchronous SMTP delays in production requests. If mail is disabled or recipients are empty, nothing is sent. No FaultNest endpoint, external storage, or SaaS service is involved.

The settings are in the application's published `config/faultnest.php`, including optional `storage.cache_store`, TTL, per-channel `min_level`, `cooldown_seconds`, `level_cooldowns`, `pending_ttl_seconds`, `tries`, `backoff_seconds`, recipient resolvers and queue selection. Adjust and cache configuration through Laravel's normal `config:cache` workflow. The `init([...])` override is applied only within that process; for queued jobs, deliverable data and channel class are serialized from the initialized configuration.

To read an unexpired group by its fingerprint without an API or UI:

```php
FaultNest::init(); // Usually already called once in AppServiceProvider.
$summary = resolve(\FaultNest\Storage\CacheGroupStore::class)
    ->find('my-project', 'production', 1, $fingerprint);
// A full list of groups and historical events requires the future Database adapter.
```

**Notification safety:** one recipient gets one independent queued job. Reservation is atomic on the configured host cache store, with lease ownership checked on completion/release. Cooldown begins after successful transport acceptance, not when queued. Failed jobs retry using Laravel Queue and release their reservation on final failure. An expired pending lease can cause a missed notification; ambiguous SMTP results may still cause duplicates. Exactly-once mail is not guaranteed. Use a **shared lock-capable store** such as Redis for multiple workers; `array` is suitable only for single-process tests. Do not use nonshared file/array cache across servers.

**Privacy:** the built-in mail body contains intrinsic, sanitized error fields and trimmed stack; optional manually supplied context is **not included in outbound mail** unless the channel's `include_context` setting is explicitly enabled. If you enable it, review what your app puts in context. Redaction of arbitrary exception messages is best effort and cannot guarantee removal of every secret.

## Manual capture

```php
use FaultNest\Domain\ErrorLevel;
use FaultNest\Laravel\Facades\FaultNest;

FaultNest::setContext('module', ['name' => 'orders']);
FaultNest::setTag('component', 'import');

$result = FaultNest::captureException($exception, [
    'user' => ['id' => $userId], // Your application decided to include this.
    'custom' => ['attempt' => 3],
]);

FaultNest::captureMessage('Import failed', ErrorLevel::Warning);
FaultNest::clearScope();
```

The keys `user` and `custom` are not special to the library. Pass only the fields you intend to disclose and persist. Never pass raw Request objects, Eloquent models, arbitrary objects, or secrets. The package does not infer data.

## Extensibility

Class-based filters (`EventFilterInterface`), transformers (`EventTransformerInterface`, may return `null`), category classifiers and fingerprint strategies are configured by class name in `config/faultnest.php`. Strategies resolve through the Laravel container. A transformer may use `ErrorEvent::withContext()`, `withMessage()` or `withLevel()`; transformed values are sanitized again, and the event's project/environment/source remain authoritative.

`EventSinkInterface` is a temporary extension boundary for the first milestone; storage and reader contracts will be refined before a stable 1.0 release.

## Local verification

```sh
composer install
composer test
composer analyse
php tests/smoke.php
php -d zend.assertions=1 -d assert.exception=1 tests/smoke-cache.php
```

No GitHub Actions are configured at this stage. All writes and tests are local to the installing application's infrastructure.

MIT licensed. Forks and pull requests are welcome.
