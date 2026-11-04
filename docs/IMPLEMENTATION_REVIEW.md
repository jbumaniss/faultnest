# FaultNest implementation review

Date: 2026-11-04. Reviewer: Claude Code (Opus 5.5). **Review authority: self-review by the agent that also wrote the fixes.** This is not an independent review and not an acceptance.

## 1. Summary

| Question | Answer |
| --- | --- |
| Are all 15 blocking findings fixed? | Yes, on `main`. |
| Do the tests pass on merged `main`? | Yes, on all 7 PHP and Laravel combinations, 3 databases, Redis, and Chromium, Firefox and WebKit on 2 real hosts. |
| Is FaultNest ready for a stable release? | **No.** Independent review, CI and the scenarios in section 8 are still open. It is technically a candidate for a first pre-release. |
| Were any packages or tags published, or anything deployed? | No. |

## 2. Merged pull requests

All were squash merged in this order. Results in section 4 are for `main` at `23a26f7`. Later commits change documentation only.

| Order | PR | Reviewed head | Commit on `main` |
| --- | --- | --- | --- |
| 1 | [#5](https://github.com/jbumaniss/faultnest/pull/5) PHP core | `479b648` | `15ff1c3` |
| 2 | [#2](https://github.com/jbumaniss/faultnest/pull/2) Browser SDK | `84704e0` | `30f9ecd` |
| 3 | [#3](https://github.com/jbumaniss/faultnest/pull/3) Receiver and source policy | `0f466c0` | `6d02e4b` |
| 4 | [#4](https://github.com/jbumaniss/faultnest/pull/4) Verification and docs | `4573bd7` | `23a26f7` |

- #3 conflicted with `main` in `README.md` and `docs/STATUS.md`. Both sides were kept, in the order SDK then receiver, and Milestone 4 then Milestone 5.
- `src/Laravel/FaultNestManager.php` merged without conflict. The Collision handler decorator, browser receiver, lifecycle sources and recursion protection are all present.
- Tests added while updating the branches: spoofed `http`, `console`, `queue` and `manual` sources in `BrowserIngestionTest`, and `E2E_BROWSER` browser selection in `tests/e2e/browser/run.mjs`.

## 3. Blocking findings: all fixed on `main`

| # | Finding | PR | Regression test |
| --- | --- | --- | --- |
| B1 | Group counters reset on every event on Laravel 13, because `cache.serializable_classes=false` makes snapshots unreadable | #5 | `RedisBackendTest` |
| B2 | MySQL deadlocks lost history: 199 of 320 events at 8 writers in one run | #5 | `DatabaseBackendTest` |
| B3 | Collision's handler decorator stopped console and queue capture | #5 | `LifecycleReportingTest`, real hosts |
| B4 | Redis lock contention dropped events: 77 of 1600 at 32 processes before the fix, 0 of 1600 in two runs after | #5 | `RedisBackendTest` |
| B5 | Anonymous exception classes leaked a NUL byte and absolute path | #5 | `LifecycleReportingTest`, `DatabaseBackendTest` |
| B6 | Invalid or oversized scope lost the real exception; unbounded breadcrumbs | #5 | `LifecycleReportingTest` |
| B7 | Redaction missed `access_token`, `client_secret`, `DB_PASSWORD`, JSON, Basic and DSN secrets | #5 | `PayloadSafetyTest` |
| B8 | Worker timeouts of the alert job were captured again | #5 | `LifecycleReportingTest` |
| B9 | SDK scope methods and hostile errors threw into the host | #2 | `robustness.test.mjs` |
| B10 | SDK lost or sent rejected events: empty messages, cross-realm errors, wire limits | #2 | `robustness.test.mjs`, Chromium |
| B11 | SDK leaked URLs through non-HTTP schemes and React component stacks | #2 | `robustness.test.mjs`, Chromium |
| B12 | SDK had no fetch timeout and unbounded in-flight requests | #2 | `robustness.test.mjs` |
| B13 | The ignore list autoloaded client-supplied class names | #5 | `EventProcessorTest` |
| B14 | Control characters in browser exception types; out-of-range line numbers | #3 | `BrowserIngestionTest` |
| B15 | Anonymous browser flood: 25 distinct messages queued 25 mail jobs | #3 | `NotificationSourcePolicyTest`, Chromium |

Also fixed: SQLite lost 6 of 320 writes to `database is locked` (with B2); `init()` merged list overrides by index (#5); `beforeSend` could not remove `stack` or `exceptionType` (#2).

The concurrency figures come from specific runs and are not stable rates.

Test defects fixed: `test_group_identity_matches_cache...` compared different fingerprints, `test_invalid_limits_fail_configuration_validation` was risky, and `client.test.mjs` asserted the SDK throw from B9. No failure was classified as environmental.

### B15: browser notification policy

- Browser events are still captured, grouped and stored.
- `allowed_sources` on each channel decides which event sources alert. The default is `http`, `console`, `queue`, `manual`.
- Matching is exact, so `browser` and `react` are separate opt-ins.
- `ChannelSettings::accepts()` applies the policy before reservation, for every channel driver. Mail has no special case, and a suppressed event reserves nothing.
- Host-owned rate limiting is still required. The list controls alerts, and accepted browser events can still add stored rows.

**Dependency handled in #3:** the approved defaults name `http`, `console` and `queue`, but automatic reports used the source `laravel`. Without a change, every automatic report would have stopped alerting. Automatic reports now name their lifecycle. Only the kind is recorded, and nothing is read from the request, command or job. This changes the stored `source` value, which is acceptable because nothing has been released.

## 4. Test results on `main` at `23a26f7`

Run from a clean export in Docker on `fnreview-net`. Service ports were not published.

### PHP and Laravel: `scripts/integration-matrix.sh matrix <dir>`

| PHP | Laravel | Testbench | PHPUnit | PHPUnit result | PHPStan level 6 | Smoke |
| --- | --- | --- | --- | --- | --- | --- |
| 8.2.35 | 12.71.0 | 10.12.0 | 11.5.57 | OK, 105 tests, 407 assertions | No errors | 4/4 |
| 8.3.36 | 12.71.0 | 10.12.0 | 12.5.43 | OK, 105 tests, 407 assertions | No errors | 4/4 |
| 8.4.27 | 12.71.0 | 10.12.0 | 12.5.43 | OK, 105 tests, 407 assertions | No errors | 4/4 |
| 8.5.12 | 12.71.0 | 10.12.0 | 12.5.43 | OK, 105 tests, 407 assertions | No errors | 4/4 |
| 8.3.36 | 13.40.0 | 11.3.0 | 12.5.43 | OK, 105 tests, 403 assertions | No errors | 4/4 |
| 8.4.27 | 13.40.0 | 11.3.0 | 12.5.43 | OK, 105 tests, 403 assertions | No errors | 4/4 |
| 8.5.12 | 13.40.0 | 11.3.0 | 12.5.43 | OK, 105 tests, 403 assertions | No errors | 4/4 |

Laravel 13.40.0 requires PHP 8.3, so PHP 8.2 with Laravel 13 does not apply. The 4-assertion difference comes from Testbench's default routes, which one test iterates over.

Lowest dependencies, `scripts/integration-matrix.sh lowest <dir> <php> <laravel>`:

| PHP | Laravel | Result |
| --- | --- | --- |
| 8.2.35 | 12.69.0 | OK, 105 tests, 407 assertions |
| 8.3.36 | 13.30.0 | OK, 105 tests, 403 assertions |

`composer.json` allows `^12.0 || ^13.0`, but `--prefer-lowest` resolved to these versions. Older releases were not tested.

### Database and Redis: `scripts/integration-matrix.sh services <dir> <php> <laravel>`

Every result was the same on all 7 combinations, except predis, which ran on the 2 combinations shown.

| Backend | Result per combination |
| --- | --- |
| SQLite (PHP-bundled) | OK, 11 tests, 72 assertions |
| MySQL 8.4.9 | OK, 11 tests, 72 assertions |
| PostgreSQL 17.11 | OK, 11 tests, 72 assertions |
| Redis 7.4.12 with phpredis | OK, 5 tests, 59 assertions |
| Redis 7.4.12 with predis 3.7.1, on PHP 8.4 with Laravel 13 and PHP 8.2 with Laravel 12 | OK, 5 tests, 59 assertions |

### Real hosts and browsers

Fresh `laravel/laravel` apps, with steps in `tests/e2e/README.md`. Playwright 1.64.1.

| Host | Scenario | Chromium | Firefox | WebKit |
| --- | --- | --- | --- | --- |
| Laravel 13.40.0, PHP 8.4.27, PostgreSQL, Redis | Passed | 15 passed, 0 failed | 15 passed, 0 failed | 14 passed, 0 failed |
| Laravel 12.71.0, PHP 8.2.35, MySQL, Redis | Passed | 15 passed, 0 failed | 15 passed, 0 failed | 14 passed, 0 failed |

Firefox ran on Linux in the Playwright Docker image, because it would not start natively on the macOS test machine. WebKit ran on macOS and skips the redirect step, because Playwright cannot fulfil redirects there. This is Playwright's WebKit build, not Safari.

Both hosts showed:

- Discovery, migrations, config publishing, `config:cache` and `route:cache` worked, with no FaultNest routes.
- Stored sources were `http` 1, `console` 2 and `queue` 1, matching the scenario.
- 6 mails reached 2 recipients through the `log` mailer, and a repeat did not send more because the cooldown held.
- Query string, cookie, header and client IP values did not appear in stored data.
- Pruning worked.
- With unreachable SMTP, the alert job failed after retries with no recursion: groups rose only by the 1 deliberate probe.

Direct probes on both hosts:

| Probe | Result |
| --- | --- |
| Spoofed source `http`, `console`, `queue`, `manual` or `laravel` | 422 |
| Unknown fields `ip`, `user`, `url`, `headers` or `cookies` | 422 |
| Exception type `App\Models\User` | 202, with no autoloading |
| 104 distinct events each for browser and React, default policy | All 202, 0 mail jobs queued |
| The same with only `browser` allowed | 104 more mail jobs, so a flood is possible only after opting in. React stays separate. |
| `composer install --no-dev` | The handler stays Laravel's own, and console capture still works |

### Other checks

| Command | Result |
| --- | --- |
| `npm ci`, `npm run typecheck`, `npm test` in `packages/browser` | Clean, 26 tests, 26 passed |
| `bash scripts/check-browser-contract.sh` | Passed |
| `bash scripts/verify.sh --full` on PHP 8.4.8 and Node 24.4.1 | Exit 0, 105 tests, 403 assertions, no PHPStan errors |
| `composer validate` | Valid |

### Regression tests against unfixed code

These ran before merging, on the integration branch, with the final tests against the unmodified source of the original PR heads, on PHP 8.4 and Laravel 13. They were not rerun on `main`.

| Suite | On unfixed source | With fixes |
| --- | --- | --- |
| PHPUnit | **23 failures, 2 errors** of 105 tests | 0 |
| MySQL | **1 failure** (concurrent writers) of 11 tests | 0 |
| Redis | **2 failures** (Laravel 13 hardening, multi-process counter) of 5 tests | 0 |
| SDK | **9 failures** of 26 tests | 0 |

## 5. Non-blocking findings (not fixed)

| Finding | Why it matters |
| --- | --- |
| Inside a host database transaction, a rollback loses the history row, and an aborted PostgreSQL transaction gives `Captured` with nothing stored | `Captured` can mislead. Documented; a dedicated connection avoids it. |
| `init()` failures are silent and cannot be inspected | Misconfiguration looks like no errors. |
| A sync-queue job failing inside an HTTP request reports as `http` | It really is the HTTP lifecycle, but a host allowing only `queue` would not alert on it. |
| `FaultNestManager::init()` is about 110 lines | Candidate for extraction. |
| None of the files in `src` declare `strict_types` | Adding it could change behaviour, so it needs its own change. |
| SDK `init()` and `createFetchTransport()` throw on invalid configuration | Treated as a design decision, because it fails at setup. Worth confirming. |
| A React boundary used with `onCaughtError` sends 2 events | Documented. |
| `keepalive` is false | Errors during page unload may be lost. |
| `.gitignore` lists `STATUS.md` though `docs/STATUS.md` is tracked | Confusing, with no functional effect seen. |
| No `.gitattributes` `export-ignore` | Tests and tooling would ship in Composer archives. |
| `PruneResult` excludes cascade-deleted samples | Counts understate deletions. |
| Browser JS `TypeError` matches PHP's `TypeError` in the ignore list | Ignoring the PHP class also drops browser events. |
| Browser events merge the server's scope | Check this is intended. |
| The `browserReceiver()` facade `@method` is undocumented | Can be added now that #3 is merged. |
| `LOCK_RETRY_MILLISECONDS` and the lock retry code are duplicated in two classes | Small duplication. |
| `AbortSignal.timeout` is used without a guard | Older browsers, such as Safari before 16 and Chrome before 103, would lose events. Not tested. |
| An `insertOrIgnore` group row can stay at count 0 if the host transaction fails after it | Readers could see an empty group. |
| `trackQueueJobs` listeners register even when disabled | Harmless, with no capture effect. |
| npm and Composer metadata are incomplete | `packages/browser/package.json` is `private`, version `0.0.0-development`, and has no `license`, `repository`, `engines` or `peerDependencies`. `composer.json` has no `keywords`, `authors`, `homepage` or `support`. Fix before publishing. |
| No GitHub Actions | Nothing runs these suites on GitHub yet. |

## 6. Design rules

Checked by searching `src` and the SDK, plus the host and Chromium tests. These cover the reviewed scenarios and do not prove the absence of every possible path.

| Rule | Result |
| --- | --- |
| No server, hosted API, dashboard or AI | Pass |
| No automatic Auth, Request, session, cookie, IP, header, route or user data | Pass. Lifecycle source records only the kind. |
| Context supplied explicitly by developers | Pass |
| Storage stays in host database, cache or Redis | Pass |
| Outbound notifications need explicit configuration | Pass |
| No package-owned routes | Pass, checked with `route:list` |
| Host controls authorization, CSRF, rate limiting and endpoints | Pass |
| Monitor failures never break the application | Pass with B9 |
| Size limits and sensitive-data protection | Pass. Message redaction is best effort and cannot catch every format. |
| PHP 8.2 to 8.5, Laravel 12 and 13 | Pass, per section 4 |

## 7. Git history and identity

- Squash commits on `main` have the author `Juris <78373300+jbumaniss@users.noreply.github.com>` and GitHub's `noreply@github.com` as committer, the same as earlier web merges.
- Nothing was force-pushed, no published commit was rewritten, and no tag exists on the remote.
- `main` history was searched for work identities, local paths and common token and private key formats, with no matches. The only passwords are the throwaway `review` value for disposable Docker services. `gitleaks` and `trufflehog` were not available, so this was a pattern search, not a full secret scan.
- No `composer.lock`, `.env`, `node_modules`, build output or logs are committed.

## 8. Not verified

- A real Octane server, including that `runningInConsole()` is false there, which the `http` source relies on.
- Successful delivery through a real SMTP server. Only `array`, `log` and an unreachable SMTP host were used.
- Real Safari and mobile browsers.
- HTTPS and cross-origin SDK transport.
- Real hosts on the other 5 combinations.
- Laravel releases older than 12.69.0 and 13.30.0.
- Redis Cluster, Sentinel, failover and network partitions.
- Other cache stores for grouping, such as Memcached, `database` and `file`.
- MariaDB, and other MySQL and PostgreSQL versions.
- A production route with CSRF, authentication and throttling. The end-to-end route exempts itself from CSRF.
- Sustained load and performance overhead.
- Errors during page unload.
- Packagist and npm publication.

## 9. Before a pre-release

1. Independent human review, especially of `DatabaseEventStore`, `ReportingExceptionHandler`, the redaction patterns and the source policy.
2. Add CI for at least the matrix and service suites.
3. Test Octane and real SMTP, or state that they are untested in the release notes.
4. Complete the publication metadata in section 5.

## 10. Reproducing

```sh
scripts/integration-matrix.sh up
scripts/integration-matrix.sh matrix /tmp/faultnest-matrix
scripts/integration-matrix.sh services /tmp/faultnest-matrix 8.4 13
scripts/integration-matrix.sh predis /tmp/faultnest-matrix 8.4 13
scripts/integration-matrix.sh lowest /tmp/faultnest-matrix 8.2 12
scripts/integration-matrix.sh down
```

The real host and browser steps are in `tests/e2e/README.md`.
