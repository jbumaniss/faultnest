# FaultNest

Lightweight, privacy-first Laravel error monitoring with customizable grouping, local storage, and notifications.

> **Status: experimental pre-release code on `main`.** It includes capture, Cache/Redis grouping, opt-in queued notifications, optional Database history and readers, the browser/React SDK and the host-owned browser receiver.
> Verified results and untested scenarios are in [docs/IMPLEMENTATION_REVIEW.md](docs/IMPLEMENTATION_REVIEW.md). No release tag, Packagist package or npm package exists yet.

## Principles

- **Your application is the server.** No FaultNest API, SaaS backend, UI, telemetry endpoint, or hosted database.
- **No automatic enrichment.** Only the exception type/message/location/trimmed stack, severity, time and configured project/environment. FaultNest never reads Laravel Auth, Request, session, IP, route, headers, or job payload for additional context.
- Optional context/tags/breadcrumbs are supplied **only by application code**. JSON-compatible values only, with size, depth and sensitive-key limits. Avoid submitting secrets. Exception messages themselves may contain sensitive details; built-in redaction is best effort, not a guarantee.
- No external traffic by default. Mail/custom channels are active only when explicitly configured and enabled, using the host application's own queue/transports.
- No HTTP routes or UI. Local PHP reader services let the host build its own UI using its own storage and authorization.
- PHP 8.2-8.5 and Laravel 12/13. Laravel 13 requires PHP 8.3 or newer. The tested combinations are listed in the [review report](docs/IMPLEMENTATION_REVIEW.md).

## Installation and setup

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

**Current milestone:** with `FAULTNEST_ENABLED=true`, group counters are stored in the host's configured Cache/Redis store by default. `CaptureResult::Captured` means processing succeeded, **not** that mail was delivered. Raw event samples are stored only when the optional host Database history is enabled and migrated. If disabled, FaultNest does not write or send anything. A custom `EventSinkInterface` may be bound to augment normal storage/notifications.

**Alert identification:** Mail subjects start with `[FaultNest][project][environment][LEVEL]`, followed by the exception/category and fingerprint. The message body also names FaultNest, project, environment, source and event ID, so filters can distinguish different host applications without relying on the sender address.

**Mail requires a running asynchronous Laravel Queue worker or Horizon**. Configure an appropriate host `QUEUE_CONNECTION` (e.g., `redis` or `database`) and the application's existing `MAIL_*` settings. The package intentionally refuses the `sync` / deferred queue drivers for delivery unless a channel explicitly sets `allow_sync=true`. This prevents accidental synchronous SMTP delays in production requests. If mail is disabled or recipients are empty, nothing is sent. No FaultNest endpoint, external storage, or SaaS service is involved.

The settings are in the application's published `config/faultnest.php`, including optional `storage.cache_store`, TTL, per-channel `min_level`, `cooldown_seconds`, `level_cooldowns`, `pending_ttl_seconds`, `tries`, `backoff_seconds`, recipient resolvers and queue selection. Adjust and cache configuration through Laravel's normal `config:cache` workflow. The `init([...])` override is applied only within that process; for queued jobs, deliverable data and channel class are serialized from the initialized configuration.

To read an unexpired group by its fingerprint without an API or UI:

```php
FaultNest::init(); // Usually already called once in AppServiceProvider.
$summary = resolve(\FaultNest\Storage\CacheGroupStore::class)
    ->find('my-project', 'production', 1, $fingerprint);
// A full group list and historical samples require enabling the optional Database adapter.
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

Throwaway-service checks (Laravel/PHP matrix, MySQL, PostgreSQL, SQLite, Redis) run on an isolated Docker network with no published ports:

```sh
scripts/integration-matrix.sh up
scripts/integration-matrix.sh matrix
scripts/integration-matrix.sh services /tmp/faultnest-matrix 8.4 13
scripts/integration-matrix.sh down
```

Browser SDK checks, and the real Laravel host and browser checks in [tests/e2e/README.md](tests/e2e/README.md):

```sh
cd packages/browser && npm ci && npm test
```

No GitHub Actions are configured at this stage. All writes and tests are local to the installing application's infrastructure.

MIT licensed. Forks and pull requests are welcome.

## Optional host Database history and local readers

FaultNest does not provide a dashboard, read API, HTTP routes, or remote database. You can **opt in** to retaining a bounded number of diagnostic event samples in your own application's database. Cache grouping and history are independent: disable `storage.enabled` if you want DB history without cache group counters.

```dotenv
FAULTNEST_ENABLED=true
FAULTNEST_DATABASE_ENABLED=true
# Optional: a database connection already configured in your Laravel application.
# FAULTNEST_DATABASE_CONNECTION=mysql
FAULTNEST_DATABASE_RETENTION_DAYS=30
FAULTNEST_DATABASE_SAMPLES_PER_DAY=3
```

After enabling history in the host application configuration, explicitly run the application's normal `php artisan migrate` command. The package registers its two tables (`faultnest_groups`, `faultnest_event_samples`) only when history is enabled. No migration runs when the package is installed or initialized. Do not enable this option before planning the host migration.

A database group counts **every captured occurrence**, while only the first configured number of per-group/per-UTC-day samples are kept. The count may be greater than the number of stored samples. Set `max_samples_per_group_per_day` to `0` to retain only group statistics and no event samples. If a sample is not retained, its explicit context cannot be recovered later. History is not automatically pruned by the package.

The installing application can query its stored records through PHP interfaces:

```php
use FaultNest\Contracts\{EventReaderInterface, GroupReaderInterface};
use FaultNest\Storage\Database\{EventQuery, GroupQuery};

// Call FaultNest::init() in your AppServiceProvider before resolving readers.
$groups = resolve(GroupReaderInterface::class)
    ->paginate(new GroupQuery('my-project', 'production', perPage: 25));

$events = resolve(EventReaderInterface::class)
    ->paginate(new EventQuery('my-project', 'production', groupId: $groups->items[0]->id));

$event = resolve(EventReaderInterface::class)
    ->find($events->items[0]->id, 'my-project', 'production');

$context = $event?->context; // Only values deliberately provided by application code.
```

The host must implement its own access control before exposing results through controllers or UI. Query methods require explicit project and environment and limit pages to 100 records. Readers are bound only when history is enabled; events never saved or already pruned cannot be retrieved. Context is returned as **stored after safety sanitization**, not reconstructed by FaultNest.

Retention is enforced only when the host explicitly runs or schedules the **local** pruning command:

```sh
php artisan faultnest:prune
# Optional bound on work per run:
php artisan faultnest:prune --max-batches=10
```

Each batch removes up to 500 expired samples or groups, with a configurable maximum of 10 batches per invocation. To keep retention up to date, schedule this command in your application (for example, daily using Laravel Scheduler). FaultNest schedules nothing itself and never deletes host application data outside its own two tables. The operation is irreversible for pruned records; take normal database backup precautions.

**History and host transactions:** the history write joins any open transaction on the same connection. A manual capture inside a host transaction that later rolls back loses its history row, and a capture inside an already-aborted PostgreSQL transaction writes nothing, while `CaptureResult` can still be `Captured` because cache grouping succeeded. Point `FAULTNEST_DATABASE_CONNECTION` at a separate connection if this matters. On SQLite with several writing processes, set `busy_timeout` on the connection. MySQL's JSON type does not preserve object key order.

**Before production:** run the integration suites against your own database driver and Laravel and PHP versions. `tests/smoke-history.php` validates only configuration and identity and does not exercise real SQL. See [Local verification](#local-verification).

## Optional browser/React SDK (experimental)

The independent TypeScript SDK lives in `packages/browser`. **It does not send or collect anything until the application calls `init({ transport })`.** The host creates and protects its own receiving route and decides whether to send any data. Extra context is supplied only by the host application; the SDK does not inspect Auth, Request, current URL, cookies, IP addresses or sessions. React 19 root handlers and ErrorBoundary capture are explicit opt-ins.

See [`packages/browser/README.md`](packages/browser/README.md). `npm` distribution is not published.

## Opt-in browser event receiver (host-owned HTTP layer)

FaultNest **never registers HTTP routes** and cannot see the current Request/Auth/user. The installing Laravel app must register and protect its own endpoint. The app may call `resolve(\FaultNest\Browser\BrowserEventReceiver::class)->accept($decodedJson)` **after its own middleware applies**. Configure `FAULTNEST_BROWSER_ENABLED=true` and `faultnest.browser.max_level` to cap any untrusted client-reported severity. The server's project, environment, minimum level, filters, redaction and grouping remain authoritative.

Minimal controller-side example (add only to your application, **not** to FaultNest):

```php
use FaultNest\Browser\BrowserEventReceiver;
use Illuminate\Http\Request;

public function report(Request $request, BrowserEventReceiver $receiver)
{
    // Configure strict auth/CSRF, throttling, and content-length limits on YOUR route.
    $result = $receiver->accept($request->json()->all());
    return response()->noContent($result->status === \FaultNest\Domain\CaptureStatus::Failed ? 422 : 202);
}
```

**Browser events are stored and grouped, but do not alert by default.** Each notification channel has an `allowed_sources` list, which defaults to `http`, `console`, `queue` and `manual`. Automatic reports use the first three, according to the running lifecycle. To alert on browser events, add `browser` and, for React, `react` to that channel (`FAULTNEST_MAIL_SOURCES` for the mail channel). Any client that can reach your endpoint can then create a new group per distinct message, and cooldown is per group, so each one can queue an alert.

**Source filtering does not replace rate limiting.** The list only controls alerts. Every accepted browser event can still add stored rows, so your route needs host-owned rate limiting whichever sources you allow.

Do not put project credentials or secrets in the browser SDK. A public POST endpoint **must have host-managed rate limiting** and payload size controls before JSON parsing. FaultNest does not attempt to identify requesters or collect IP addresses. The PHP receiver is transport-neutral and accepts only the documented browser fields. Unknown fields (including `project`, `environment`, `user` and `url`) are rejected; explicit user/application context belongs under `context`. It stores only sanitized, bounded diagnostics through existing host-owned sinks.

For host-defined browser diagnostics, the optional `tags.route` (static Inertia page identifier such as `Review/Show`) and `tags.location` (script filename, line and column such as `app-abc.js:1:234`) are printed as `Route` and `Location` in mail notifications, without enabling `include_context`. Only `browser` and `react` sources and strictly formatted values are rendered. Raw stack traces, request paths, URLs, query strings and user identifiers must not be sent as these tags. Malformed values are ignored by the mail formatter. These tags remain outside the default error fingerprint; older browser payloads still work unchanged.
