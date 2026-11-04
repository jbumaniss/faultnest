#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
if [[ ! -f packages/browser/dist/index.js || ! -f src/Browser/BrowserEventParser.php ]]; then
  echo 'SKIP: browser/receiver contract components not both present'
  exit 0
fi
command -v node >/dev/null || { echo 'Node.js required for browser contract check' >&2; exit 1; }
command -v php >/dev/null || { echo 'PHP required for browser contract check' >&2; exit 1; }
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
node --input-type=module > "$tmp" <<'JS'
import { FaultNestClient } from './packages/browser/dist/index.js';
const client = new FaultNestClient();
client.init({ transport: event => console.log(JSON.stringify(event)) });
client.setContext('explicit', { orderId: 42 });
const error = new Error('failure');
error.stack = 'Error: failure\n at render (https://example.test/orders/123/app.js?secret=hidden:24:3)';
if (!await client.captureException(error)) process.exitCode = 1;
JS
php -d zend.assertions=1 -d assert.exception=1 -r '
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, "FaultNest\\")) {
        require getcwd()."/src/".str_replace("\\", "/", substr($class, 10)).".php";
    }
});
$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$parsed = (new FaultNest\Browser\BrowserEventParser())->parse($data);
assert($parsed->source === "browser");
assert($parsed->message === "failure");
assert($parsed->context["explicit"]["orderId"] === 42);
assert(str_contains($parsed->stack, "/app.js:24:3"));
assert(!str_contains($parsed->stack, "secret="));
echo "PASS: Node browser event is accepted by PHP receiver schema\n";
' "$tmp"
