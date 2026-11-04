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

## Subsequent milestones (not implemented)

1. Optional host-database history, bounded retention, local PHP reader/query services and pagination, with caller context round-trip.
2. Independent browser/React SDK and transport-independent server-side payload handler; host supplies endpoint/routes/security.
3. Laravel 12/13 and PHP 8.2–8.5 compatibility matrix, parallel Redis test, queue delivery tests with Composer/Testbench, PHPStan verification.

Never add a hosted FaultNest service, bundle a UI/HTTP API, auto-collect request/user information, send unconfigured outbound telemetry, or add AI integrations.
