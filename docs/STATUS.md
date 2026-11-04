# FaultNest implementation status

Design baseline: FaultNest HLD/MLD/LLD design 0.2 (2026-11-04). No implementation existed at repository creation.

## Milestone 1: capture core (implemented in this branch)

- Composer metadata, package discovery, opt-in `FaultNest::init()`.
- Laravel exception reporting hook that does not replace default logging.
- Manual `captureException`, `captureMessage`, `setContext`, `setTag`, `addBreadcrumb`, `clearScope`.
- Only intrinsic diagnostics; no Auth/Request/route/job information is collected.
- Scoped explicit context, immutable events, severity, classification, stable versioned fingerprint.
- Config-driven filters and transformers with `null` discard, second sanitization, failure isolation.
- Configurable `EventSinkInterface` with safe no-op fallback when storage disabled.
- PHPUnit core tests and dependency-free smoke test. Full compatibility tests still pending.

## Milestone 2: host grouping and notification delivery (implemented, integration verification pending)

- Cache/Redis group snapshots with atomic updates and TTL, locally readable by fingerprint.
- Notification reservations and cooldown owned by per-delivery tokens in shared host cache.
- Separate per-target queued notifications; Mail adapter plus custom `NotificationChannelInterface` and `RecipientResolverInterface`.
- Configurable severity, intervals, queue, retry, backoff; default disabled outbound delivery.
- `allow_sync=false` guards host applications from inadvertently blocking HTTP requests on SMTP.
- Notification delivery failures skip re-monitoring to avoid a notification recursion loop.
- New feature test sources and dependency-free state-machine smoke tests. Real Laravel integration and multiprocess Redis tests **not executed here**.

## Milestone 3: host Database history and local read services (implemented, verification pending)

- Opt-in host database writer with atomic group counts and per-group/UTC-day sample quota.
- Two host-owned tables registered for migration only when history is enabled; no implicit migrations.
- Typed `GroupReaderInterface` / `EventReaderInterface`, bounded pagination, mandatory project/environment scope; context round-trip from retained samples.
- Explicit `faultnest:prune` command and configurable retention; never automatically scheduled.
- In-memory SQLite Testbench test sources added but **not run here** (no PDO SQLite driver or Composer in environment).
- `tests/smoke-history.php` passes; it is not a substitute for an actual database integration test.

### Remaining blockers

1. Execute Laravel 12 and 13 / PHP 8.2–8.5 dependency and database integration tests; verify SQLite, MySQL and PostgreSQL behavior, migration registration and concurrent transaction behavior.
2. Independent browser/React SDK and host-owned payload handler.
3. Failure-path / confidentiality review before release. This milestone is not production certified.

## Subsequent milestones (not implemented)

1. Independent browser/React SDK and transport-independent server-side payload handler; host supplies endpoint/routes/security.
2. Laravel 12/13 and PHP 8.2–8.5 compatibility matrix, parallel Redis test, queue delivery tests with Composer/Testbench, PHPStan verification.

Never add a hosted FaultNest service, bundle a UI/HTTP API, auto-collect request/user information, send unconfigured outbound telemetry, or add AI integrations.

## Milestone 4: optional browser/React SDK (separate draft PR)

- Standalone TypeScript browser capture with opt-in global listeners, Promise rejection capture, manual events and explicit context.
- `beforeSend`, severity threshold, idempotent initialization, `dispose`, `flush`, `close` and non-recursive error isolation.
- Explicit transport and opt-in fetch adapter (no default endpoint or hosted server).
- React 19 root error handler adapter, plus ErrorBoundary capture helper; no React dependency needed.
- Runtime tests using Node's built-in test runner; PHP host event receiver remains a separate milestone.
