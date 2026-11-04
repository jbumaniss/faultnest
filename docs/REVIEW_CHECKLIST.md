# FaultNest release review and test matrix

**Status: experimental code, no production certification, no release tag.** Check items only when evidence is available. Draft PRs may exist independently; do not assume their code has been integrated into `main`.

## Verified in the initial implementation environment

- [x] PHP 8.4 CLI syntax checks for local package files.
- [x] Dependency-free `smoke.php`, `smoke-cache.php`, `smoke-history.php` passed (cache doubles, not Redis).
- [x] TypeScript compile and Node built-in tests for browser SDK draft PR #2.
- [x] Dependency-free browser receiver smoke test for draft PR #3.

## Mandatory compatibility checks before release

- [ ] `composer install` and PHPUnit/Testbench run against Laravel **12** with PHP 8.2, 8.3, 8.4 and 8.5 where supported.
- [ ] `composer install` and PHPUnit/Testbench run against Laravel **13** with PHP 8.3, 8.4 and 8.5 where supported.
- [ ] PHPStan analysis passes; framework integration APIs and all classes have correct signatures.
- [ ] `config:cache` and provider discovery work in a real Laravel host.
- [ ] Exception reporting is opt-in/idempotent, does not interfere with normal Laravel error reporting, and handles HTTP/Console/Queue lifecycles.
- [ ] Scoped explicit context is cleared across requests, Octane workers and queue jobs.
- [ ] SQLite, MySQL and PostgreSQL migrations/history/group readers/pruning pass with temporary isolated databases.
- [ ] Parallel DB group updates preserve counters and daily sample limits on MySQL/PostgreSQL.
- [ ] Shared Redis: multiprocess grouping, lock ownership, notification reservation/cooldown, TTL and retry behavior.
- [ ] Mail: `Mail::fake()`, real async queue job delivery, failure callbacks, duplicate recipients, independent per-recipient cooldowns.
- [ ] No outbound email when all notification switches are disabled; no implicit synchronous SMTP.
- [ ] Browser SDK and PHP receiver wire-schema interoperation; same-origin and HTTPS transport behavior in real browsers.
- [ ] Host-owned public/authorized routes with CSRF, authentication policy, throttling and payload limits; invalid payloads cannot affect host availability.
- [ ] Arbitrary user-supplied context/stack/messages sanitized and bounded; no secrets, URLs, user/request data gathered automatically.
- [ ] No FaultNest server, HTTP routes, dashboard, AI integrations, or external storage added.
- [ ] Package Composer and planned npm metadata, PHP/JS exports and docs reviewed before any Packagist/npm publication.

## Safe local commands

```sh
bash scripts/verify.sh --smoke
composer install
bash scripts/verify.sh --full
cd packages/browser && npm install && npm test
```

The script does not run migrations, deploy, start queues, connect to production, or create external services. **Do not run existing application migrations without verifying an isolated test connection and explicit authorization.**

## Open technical risks to inspect

1. Expired queued notification leases may suppress delayed jobs; SMTP is not exactly-once. Test recovery/failure semantics before release.
2. Cache locks require a **shared** lock-capable store across workers; `array` is only a local single-process test substitute.
3. Database `lockForUpdate` semantics vary across drivers, especially SQLite; multi-worker tests are essential.
4. Error messages may embed secrets in unforeseen formats. Default redaction is best effort; never assume arbitrary messages are safe.
5. A user-built UI has to authorize every read; project/environment filters alone are not access control.
