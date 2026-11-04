# FaultNest

Lightweight, privacy-first Laravel error monitoring with customizable grouping, local storage, and notifications.

> **Development status: initial capture-core milestone. Not a complete V1 release.**
> Cache/Redis aggregation, database history/readers, notification delivery, browser/React SDK and compatibility integration tests are **not implemented yet**. No release tag or Packagist publication is available.

## Principles

- **Your application is the server.** No FaultNest API, SaaS backend, UI, telemetry endpoint, or hosted database.
- **No automatic enrichment.** Only the exception type/message/location/trimmed stack, severity, time and configured project/environment. FaultNest never reads Laravel Auth, Request, session, IP, route, headers, or job payload for additional context.
- Optional context/tags/breadcrumbs are supplied **only by application code**. JSON-compatible values only, with size, depth and sensitive-key limits. Avoid submitting secrets. Exception messages themselves may contain sensitive details; built-in redaction is best effort, not a guarantee.
- No network traffic from this milestone. Notifications will remain explicitly opt-in in subsequent milestones.
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

**Current milestone does not persist errors or send mail.** `CaptureResult::Captured` means local processing succeeded, not that an event was saved or delivered. The default event sink is deliberately `NullEventSink`; installing applications can bind their own `EventSinkInterface` as an interim local sink. Persistence and notifications require the next milestones.

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
```

No GitHub Actions are configured at this stage. All writes and tests are local to the installing application's infrastructure.

MIT licensed. Forks and pull requests are welcome.
