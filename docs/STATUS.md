# FaultNest implementation status

Design baseline: FaultNest HLD/MLD/LLD design 0.2 (2026-11-04). All milestones below are merged into `main`. Test results and untested scenarios are in [IMPLEMENTATION_REVIEW.md](IMPLEMENTATION_REVIEW.md). No release tag or package has been published.

## Milestone 1: capture core

- Composer metadata, package discovery, opt-in `FaultNest::init()`.
- Laravel exception reporting hook that does not replace default logging.
- Manual `captureException`, `captureMessage`, `setContext`, `setTag`, `addBreadcrumb`, `clearScope`.
- Only intrinsic diagnostics; no Auth/Request/route/job information is collected.
- Scoped explicit context, immutable events, severity, classification, stable versioned fingerprint.
- Config-driven filters and transformers with `null` discard, second sanitization, failure isolation.
- Configurable `EventSinkInterface` with safe no-op fallback when storage disabled.
- PHPUnit core tests and dependency-free smoke test.

## Milestone 2: host grouping and notification delivery

- Cache/Redis group snapshots with atomic updates and TTL, locally readable by fingerprint.
- Notification reservations and cooldown owned by per-delivery tokens in shared host cache.
- Separate per-target queued notifications; Mail adapter plus custom `NotificationChannelInterface` and `RecipientResolverInterface`.
- Configurable severity, intervals, queue, retry, backoff; default disabled outbound delivery.
- `allow_sync=false` guards host applications from inadvertently blocking HTTP requests on SMTP.
- Notification delivery failures skip re-monitoring to avoid a notification recursion loop.
- Feature tests, multi-process Redis tests and real Laravel host checks.

## Milestone 3: host Database history and local read services

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

## Milestone 4: optional browser/React SDK

- Standalone TypeScript browser capture with opt-in global listeners, Promise rejection capture, manual events and explicit context.
- `beforeSend`, severity threshold, idempotent initialization, `dispose`, `flush`, `close` and non-recursive error isolation.
- Explicit transport and opt-in fetch adapter (no default endpoint or hosted server).
- React 19 root error handler adapter, plus ErrorBoundary capture helper; no React dependency needed.
- Node tests, plus Chromium, Firefox and WebKit checks against real Laravel hosts.

## Milestone 5: host-owned browser receiver and notification source policy

- `BrowserEventReceiver::accept(array $payload)` accepts only decoded event data. It does not read Request/Auth, create routes, or send/retain data outside existing host sinks.
- Strict schema and bounded event fields, explicit-only context/tags/breadcrumbs, server-authoritative project/environment and severity ceiling.
- Intrinsic JavaScript stack is reduced to basename, line and call; URL query/fragment are not retained.
- Each notification channel has `allowed_sources`, defaulting to `http`, `console`, `queue` and `manual`, so browser and React events are stored but do not alert unless a channel opts in. Automatic reports name their lifecycle kind.
- Endpoints must be created and protected by host projects with auth/CSRF/size/rate limiting middleware.

## Not done yet

- CI, a real Octane run and successful delivery through a real SMTP server.
- Packagist and npm publication, and a first release tag.

Never add a hosted FaultNest service, bundle a UI/HTTP API, auto-collect request/user information, send unconfigured outbound telemetry, or add AI integrations.
