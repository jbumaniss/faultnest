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
// Wire-limit edge cases the receiver must accept as well.
client.clearScope();
await client.captureException(new Error(''));
for (let i = 0; i < 40; i++) client.setTag(`t${i}`, 'v');
for (let i = 0; i < 20; i++) client.addBreadcrumb('b'.repeat(400), { d: 'y'.repeat(3000) });
await client.captureException(new TypeError('bulky'), { context: { c: 'z'.repeat(7000) } });
JS
php -d zend.assertions=1 -d assert.exception=1 -r '
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, "FaultNest\\")) {
        require getcwd()."/src/".str_replace("\\", "/", substr($class, 10)).".php";
    }
});
$lines = array_values(array_filter(explode("\n", file_get_contents($argv[1]))));
assert(count($lines) === 3);
$parser = new FaultNest\Browser\BrowserEventParser();
$events = array_map(fn ($l) => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines);
$parsed = $parser->parse($events[0]);
assert($parser->parse($events[1])->message === "Error");
assert($parser->parse($events[2])->message === "bulky");
assert($parsed->source === "browser");
assert($parsed->message === "failure");
assert($parsed->context["explicit"]["orderId"] === 42);
assert(str_contains($parsed->stack, "/app.js:24:3"));
assert(!str_contains($parsed->stack, "secret="));
echo "PASS: Node browser event is accepted by PHP receiver schema\n";
' "$tmp"
