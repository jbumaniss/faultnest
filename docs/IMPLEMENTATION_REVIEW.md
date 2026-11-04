# FaultNest implementation review

Date: 2026-11-04. Reviewer: Claude Code (Opus 5.5). **Review authority: self-review by the agent that also wrote the fixes.** This is not an independent review and not an acceptance. All PRs stay in Draft until an independent review.

## 1. Summary

| Question | Answer |
| --- | --- |
| Are all 15 blocking findings fixed? | Yes, in the Draft PRs. None has reached `main`. |
| Do the PRs pass when merged together? | Yes, on all 7 PHP and Laravel combinations, 3 databases, Redis, and real Chromium on 2 real hosts. |
| Is FaultNest ready to release? | **No.** Passing tests do not establish that. Independent review, CI, and the scenarios in section 8 are still open. |
| Were any PRs merged, or packages published or deployed? | No. |

## 2. Pull requests

| PR | Branch | Head | Base | Ready for independent review? |
| --- | --- | --- | --- | --- |
| [#5](https://github.com/jbumaniss/faultnest/pull/5) PHP core | `fix/php-core-review` | `479b648` | `main` | Yes |
| [#2](https://github.com/jbumaniss/faultnest/pull/2) Browser SDK | `feature/browser-sdk` | `84704e0` | `main` | Yes |
| [#3](https://github.com/jbumaniss/faultnest/pull/3) Receiver and source policy | `feature/browser-receiver` | `5cac213` | `main` | Yes, after #5 for merging |
| [#4](https://github.com/jbumaniss/faultnest/pull/4) Verification and docs | `chore/release-verification` | See the PR | `main` | Yes |

**Recommended merge order: #5, #2, #3, #4.**

- #5 goes first because `main` has a failing test and 13 PHPStan errors, so `scripts/verify.sh --full` fails on every other PR until it lands.
- #3 must not merge before #5. The B13 autoload fix lives in #5 because B5's anonymous class handling changes the same method. Separating them would have meant duplicating the fix or creating a merge conflict.
- #4 goes last because its service and end-to-end commands exercise code and test suites from the other three.
- #2 and #3 conflict in `README.md` and `docs/STATUS.md`. Both sides are documentation, and **both must be kept**. Keep the `## Optional browser/React SDK (experimental)` section and `## Milestone 4` as well as the receiver's sections. A hasty resolution during integration dropped them once, so check them after resolving.

Commits:

| PR | Commits |
| --- | --- |
| #5 | `ebf2c5f` grouping, `d500c61` history writes, `ea1c3c1` decorated handlers, `5e88ff7` scope and anonymous classes, `a8824ca` redaction, `419a6e6` autoload, `12e41a9` PHPStan, `3bfbae5` test defects, `13c60e8` feature tests, `479b648` integration suites |
| #2 | `d1419de` SDK fixes and tests, `84704e0` package lock |
| #3 | `1cfcdd9` receiver hardening, `5dba563` lifecycle sources, `5cac213` source policy |
| #4 | `03e45e9` matrix and end-to-end checks, plus the commit carrying this report |

## 3. Blocking findings: all fixed in the PRs

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

## 4. Test results

Run on the temporary integration branch containing all four PRs, except the last line of each table, in Docker on `fnreview-net`. Service ports were not published.

### PHP and Laravel: `scripts/integration-matrix.sh matrix <dir>`

| PHP | Laravel | Testbench | PHPUnit | PHPUnit result | PHPStan level 6 | Smoke |
| --- | --- | --- | --- | --- | --- | --- |
| 8.2.35 | 12.71.0 | 10.12.0 | 11.5.57 | OK, 105 tests, 403 assertions | No errors | 4/4 |
| 8.3.36 | 12.71.0 | 10.12.0 | 12.5.43 | OK, 105 tests, 403 assertions | No errors | 4/4 |
| 8.4.27 | 12.71.0 | 10.12.0 | 12.5.43 | OK, 105 tests, 403 assertions | No errors | 4/4 |
| 8.5.12 | 12.71.0 | 10.12.0 | 12.5.43 | OK, 105 tests, 403 assertions | No errors | 4/4 |
| 8.3.36 | 13.40.0 | 11.3.0 | 12.5.43 | OK, 105 tests, 399 assertions | No errors | 4/4 |
| 8.4.27 | 13.40.0 | 11.3.0 | 12.5.43 | OK, 105 tests, 399 assertions | No errors | 4/4 |
| 8.5.12 | 13.40.0 | 11.3.0 | 12.5.43 | OK, 105 tests, 399 assertions | No errors | 4/4 |

Laravel 13.40.0 requires PHP 8.3, so PHP 8.2 with Laravel 13 does not apply. The 4-assertion difference comes from Testbench's default routes, which one test iterates over.

Lowest dependencies, `scripts/integration-matrix.sh lowest <dir> <php> <laravel>`:

| PHP | Laravel | Result |
| --- | --- | --- |
| 8.2.35 | 12.69.0 | OK, 105 tests, 403 assertions |
| 8.3.36 | 13.30.0 | OK, 105 tests, 399 assertions |

`composer.json` allows `^12.0 || ^13.0`, but `--prefer-lowest` resolved to these versions. Older releases were not tested, and I did not investigate why.

### Database and Redis: `scripts/integration-matrix.sh services <dir> <php> <laravel>`

Every result was the same on all 7 combinations.

| Backend | Result per combination |
| --- | --- |
| SQLite (PHP-bundled) | OK, 11 tests, 72 assertions |
| MySQL 8.4.9 | OK, 11 tests, 72 assertions |
| PostgreSQL 17.11 | OK, 11 tests, 72 assertions |
| Redis 7.4.12 with phpredis | OK, 5 tests, 59 assertions |
| Redis 7.4.12 with predis 3.7.1, PHP 8.4 and Laravel 13 only | OK, 5 tests, 59 assertions |

### Real hosts and browser

Fresh `laravel/laravel` apps, with steps in `tests/e2e/README.md`.

| Host | Scenario | Chromium (Playwright 1.64.1, React 19.3.1) |
| --- | --- | --- |
| Laravel 13.40.0, PHP 8.4.27, PostgreSQL, Redis | Passed | 15 passed, 0 failed |
| Laravel 12.71.0, PHP 8.2.35, MySQL, Redis | Passed | 15 passed, 0 failed |

Both hosts showed:

- Discovery, migrations, `config:cache` and `route:cache` worked, with no FaultNest routes.
- Stored sources were `http` 1, `console` 2 and `queue` 1, matching the scenario.
- 6 mails reached 2 recipients through the `log` mailer, and a repeat did not send more because the cooldown held.
- Browser events were stored, and the queue stayed empty.
- Query string, cookie, Authorization header and client IP values did not appear in stored data.
- With unreachable SMTP, the alert job failed after retries with no recursion: groups rose only by the 1 deliberate probe.

SDK: `npm test` 26 of 26, `tsc` clean.

### Each PR on its own (PHP 8.4.27, Laravel 13)

| Ref | PHPUnit | PHPStan | Smoke | SDK |
| --- | --- | --- | --- | --- |
| #5 `479b648` | OK, 82 tests, 281 assertions | No errors | 3/3 | n/a |
| #2 `84704e0` | 27 tests, **1 failure, 1 risky** | **13 errors** | 3/3 | 26/26 |
| #3 `5cac213` | 50 tests, **1 failure, 1 risky** | **13 errors** | 4/4 | n/a |
| #4 `03e45e9` | 27 tests, **1 failure, 1 risky** | **13 errors** | 3/3 | n/a |

The failure, risky test and 13 PHPStan errors on #2, #3 and #4 are all the existing `main` defects that #5 fixes. They do not come from those PRs. #5's service suites on its own base: MySQL 11 tests OK, Redis 5 tests OK.

Merged with #5:

| Merge | PHPUnit | PHPStan | Smoke |
| --- | --- | --- | --- |
| #5 + #2 | OK, 82 tests, 281 assertions | No errors | 3/3 |
| #5 + #3 | OK, 105 tests, 399 assertions | No errors | 4/4 |
| #5 + #4 | OK, 82 tests, 281 assertions | No errors | 3/3 |

### Regression tests against unfixed code

The final tests ran against the unmodified merged source of the original PR heads, on PHP 8.4 and Laravel 13.

| Suite | On unfixed source | With fixes |
| --- | --- | --- |
| PHPUnit | **23 failures, 2 errors** of 105 tests | 0 |
| MySQL | **1 failure** (concurrent writers) of 11 tests | 0 |
| Redis | **2 failures** (Laravel 13 hardening, multi-process counter) of 5 tests | 0 |
| SDK | **9 failures** of 26 tests | 0 |

The PHPUnit failures include all the source policy tests except `test_server_side_manual_events_still_notify_by_default`, which should pass either way.

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
| The `browserReceiver()` facade `@method` is undocumented | Dropped from #5, where the class does not exist, and adding it in #3 would conflict. A small follow-up after merging. |
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

- Every commit in these PRs has the author and committer `Juris <78373300+jbumaniss@users.noreply.github.com>`. This was checked before each push.
- Three unpublished local merge commits on `review/integration` carried a work email. They were recreated locally with this identity and identical trees, and were never pushed.
- Nothing was force-pushed, and no published commit was rewritten.
- The new commits were searched for work identities, local paths and common token and private key formats, with no matches. The only passwords are the throwaway `review` value for disposable Docker services. `gitleaks` and `trufflehog` were not available, so this was a pattern search, not a full secret scan.
- `composer.lock` was not committed.

## 8. Not verified

- A real Octane server, including that `runningInConsole()` is false there, which the `http` source relies on.
- Successful delivery through a real SMTP server. Only `array`, `log` and an unreachable SMTP host were used.
- Firefox, Safari and mobile browsers.
- HTTPS and cross-origin SDK transport.
- Real hosts on the other 5 combinations.
- Laravel releases older than 12.69.0 and 13.30.0.
- Predis on other combinations in this final run. An earlier run also passed on PHP 8.2 and Laravel 12.
- Redis Cluster, Sentinel, failover and network partitions.
- Other cache stores for grouping, such as Memcached, `database` and `file`.
- MariaDB, and other MySQL and PostgreSQL versions.
- A production route with CSRF, authentication and throttling. The end-to-end route exempts itself from CSRF.
- Sustained load and performance overhead.
- Errors during page unload.
- Packagist and npm publication.

## 9. Before a release

1. Independent review of all four PRs, especially `DatabaseEventStore`, `ReportingExceptionHandler`, the redaction patterns and the source policy.
2. Merge in the order above, then rerun the matrix on the merged `main`, because these results apply to the integration branch.
3. Add CI for at least the matrix and service suites.
4. Test Octane and real SMTP, and review publication metadata.

## 10. Reproducing

```sh
scripts/integration-matrix.sh up
scripts/integration-matrix.sh matrix /tmp/faultnest-matrix
scripts/integration-matrix.sh services /tmp/faultnest-matrix 8.4 13
scripts/integration-matrix.sh predis /tmp/faultnest-matrix 8.4 13
scripts/integration-matrix.sh lowest /tmp/faultnest-matrix 8.2 12
scripts/integration-matrix.sh down
```

The real host and Chromium steps are in `tests/e2e/README.md`. The `services` command needs #5, and the end-to-end steps need all of the PRs.
