# Real-host end-to-end check

Builds a fresh `laravel/laravel` application in a container, installs this package from a path repository, wires it as the main README instructs and exercises HTTP, Console, Queue, mail and a real Chromium with React 19. Everything stays on the isolated `fnreview-net` network, and the host app's port is published on `127.0.0.1` only.

Requires `scripts/integration-matrix.sh up`, a copy of the package without `vendor` in `$PKG`, and a built page in `$ASSETS` (`e2e-page.html` from `browser/page.html`, `e2e-bundle.js` from esbuilding `browser/entry.jsx` with `react`, `react-dom` and `playwright` installed outside this repository).

```sh
docker run -d --name fnreview-host13 --network fnreview-net -p 127.0.0.1:18013:8000 -v "$PKG:/pkg:ro" -v "$PWD/tests/e2e/host:/e2e:ro" -v "$ASSETS:/e2e-assets:ro" fnreview-php:8.4 sleep 3600
docker exec fnreview-host13 bash /e2e/run.sh 13 pgsql
docker exec fnreview-host13 bash /e2e/scenario.sh
node tests/e2e/browser/run.mjs http://127.0.0.1:18013
```
