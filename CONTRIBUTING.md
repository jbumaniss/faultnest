# Contributing to FaultNest

Forks, issues and focused pull requests are welcome. FaultNest is a privacy-first library that operates solely inside the installing application's infrastructure.

## Working agreement

1. Open an issue or describe the change in the pull request. Keep changes focused and explain behavior and compatibility implications.
2. Do not add a hosted service, central telemetry, automatic Auth/Request/IP/session enrichment, hidden outbound HTTP traffic, AI dependency, or bundled dashboard.
3. Browser events are sent only to explicit host-owned transports. PHP storage, queues and notifications use the host application's configured infrastructure.
4. Preserve extensibility using small, documented contracts and protected extension methods where needed; do not add unused interfaces or make all methods public.
5. Never include secrets, customer data, production dumps, credentials or captured real errors in issues or test fixtures.

## Local checks

```sh
bash scripts/verify.sh --smoke
composer install
bash scripts/verify.sh --full
```

When `packages/browser` is present, install its development dependencies with `npm ci` (after a lockfile exists) or `npm install` during initial unpublished development, then run its tests. No pre-commit hook or GitHub Actions workflow is required.

See [Review checklist](docs/REVIEW_CHECKLIST.md) for release-blocking verification. Tests must use isolated temporary databases and must never operate on an existing production or development database.

## Pull requests

- Add unit tests for changed behavior and integration tests where Laravel/cache/SQL/queue behavior is involved.
- Document public API changes and backwards compatibility.
- State the exact checks executed and what was not verified.
- Do not merge or tag a release while review blockers remain.

MIT licensed. Contributions submitted for inclusion are under the repository's MIT license.
