# FaultNest implementation status

Design baseline: FaultNest HLD/MLD/LLD design 0.2 (2026-11-04). No implementation existed at repository creation.

## Milestone 1: capture core (this branch)

- Composer metadata, package discovery, opt-in `FaultNest::init()`.
- Laravel exception reporting hook that does not replace default logging.
- Manual `captureException`, `captureMessage`, `setContext`, `setTag`, `addBreadcrumb`, `clearScope`.
- Only intrinsic diagnostics; no Auth/Request/route/job information is collected.
- Scoped explicit context, immutable events, severity, classification, stable versioned fingerprint.
- Config-driven filters and transformers with `null` discard, second sanitization, failure isolation.
- A configurable `EventSinkInterface` with safe no-op default; **no persistence** yet.
- PHPUnit core tests and dependency-free smoke test. Full compatibility tests still pending.

## Subsequent milestones (not implemented)

1. Host Cache/Redis atomic group aggregation, notification reservation/cooldown and explicitly enabled Mail queue adapter with bounded retries.
2. Optional host-database history, bounded retention, local PHP reader/query services and pagination, with caller context round-trip.
3. Independent browser/React SDK and transport-independent server-side payload handler; host supplies endpoint/routes/security.
4. Full Laravel 12/13 and PHP 8.2–8.5 test matrix, PHPStan and integration verification.

Never add a hosted FaultNest service, bundle a UI/HTTP API, auto-collect request/user information, send unconfigured outbound telemetry, or add AI integrations.
