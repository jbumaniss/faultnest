#!/usr/bin/env bash
set -euo pipefail

# All checks run locally. This script never deploys, migrates a host database,
# invokes external services, or changes Git branches.
mode="${1:---smoke}"
if [[ "$mode" != '--smoke' && "$mode" != '--full' ]]; then
  printf 'Usage: %s [--smoke|--full]\n' "$0" >&2
  exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

command -v php >/dev/null || { echo 'PHP is required' >&2; exit 1; }

printf 'PHP runtime: '; php -r 'echo PHP_VERSION, "\n";'
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find src config database tests -type f -name '*.php' -print0)
printf 'PASS: PHP syntax\n'

for file in tests/smoke.php tests/smoke-cache.php tests/smoke-history.php tests/smoke-browser.php; do
  if [[ -f "$file" ]]; then
    php -d zend.assertions=1 -d assert.exception=1 "$file"
  fi
done

if [[ -d packages/browser ]]; then
  if command -v tsc >/dev/null 2>&1; then
    (cd packages/browser && tsc -p tsconfig.json)
    (cd packages/browser && node --test tests/*.test.mjs)
    bash scripts/check-browser-contract.sh
  elif [[ -x packages/browser/node_modules/.bin/tsc ]]; then
    (cd packages/browser && ./node_modules/.bin/tsc -p tsconfig.json)
    (cd packages/browser && node --test tests/*.test.mjs)
    bash scripts/check-browser-contract.sh
  elif [[ "$mode" == '--full' ]]; then
    echo 'TypeScript dependencies are missing: run npm ci in packages/browser' >&2
    exit 1
  else
    echo 'SKIP: browser tests (install TypeScript first)'
  fi
fi

if [[ "$mode" == '--full' ]]; then
  if [[ ! -x vendor/bin/phpunit || ! -x vendor/bin/phpstan ]]; then
    echo 'Composer dev dependencies missing: run composer install first' >&2
    exit 1
  fi
  vendor/bin/phpunit
  vendor/bin/phpstan analyse --no-progress
else
  echo 'SMOKE ONLY: Testbench/PHPUnit, PHPStan, real DB, Queue and Redis tests NOT verified.'
fi
