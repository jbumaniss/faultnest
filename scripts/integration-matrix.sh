#!/usr/bin/env bash
# Isolated Laravel/PHP/DB/Redis verification. Everything runs on a dedicated Docker network with
# no published ports and a named container prefix; nothing touches other containers or host services.
# Usage: scripts/integration-matrix.sh up | matrix [workdir] | services [workdir] | down
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
net=fnreview-net
cmd="${1:-matrix}"
work="${2:-${TMPDIR:-/tmp}/faultnest-matrix}"
combos=("8.2 12" "8.3 12" "8.4 12" "8.5 12" "8.3 13" "8.4 13" "8.5 13")

image() {
  docker build -q -t "fnreview-php:$1" --build-arg "V=$1" - >/dev/null <<'D'
ARG V
FROM php:${V}-cli
RUN apt-get update -qq && apt-get install -y -qq libzip-dev libpq-dev libsqlite3-dev unzip git >/dev/null \
 && docker-php-ext-install -j4 pdo_mysql pdo_pgsql pcntl zip >/dev/null \
 && pecl install redis >/dev/null && docker-php-ext-enable redis \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null
D
}

up() {
  docker network inspect "$net" >/dev/null 2>&1 || docker network create "$net" >/dev/null
  docker run -d --rm --name fnreview-mysql --network "$net" -e MYSQL_ROOT_PASSWORD=review -e MYSQL_DATABASE=faultnest_it mysql:8.4 >/dev/null
  docker run -d --rm --name fnreview-pgsql --network "$net" -e POSTGRES_PASSWORD=review -e POSTGRES_DB=faultnest_it postgres:17 >/dev/null
  docker run -d --rm --name fnreview-redis --network "$net" redis:7 >/dev/null
  until docker exec fnreview-mysql mysqladmin ping -uroot -preview --silent 2>/dev/null; do sleep 2; done
  until docker exec fnreview-pgsql pg_isready -U postgres >/dev/null 2>&1; do sleep 2; done
  sleep 8 # MySQL's entrypoint restarts once after init.
  until docker exec fnreview-mysql mysql -uroot -preview -e 'select 1' faultnest_it >/dev/null 2>&1; do sleep 2; done
}

down() {
  docker rm -f fnreview-mysql fnreview-pgsql fnreview-redis >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
}

# Copy the tree (no vendor) so that per-combination composer resolution never touches the checkout.
prepare() {
  local php="$1" laravel="$2" dir="$work/php$1-l$2"
  mkdir -p "$dir"
  rsync -a --delete --exclude vendor --exclude node_modules --exclude composer.lock --exclude .git --exclude dist --exclude .phpstan "$root/" "$dir/"
  image "$php"
  docker run --rm -v "$dir:/app" -w /app "fnreview-php:$php" sh -c \
    "composer update -q --no-interaction --prefer-dist --with 'laravel/framework:^$laravel.0' 2>&1 | tail -20"
}

run() { local php="$1" laravel="$2" dir="$work/php$1-l$2"; shift 2
  docker run --rm --network "$net" -v "$dir:/app" -w /app "$@"
}

case "$cmd" in
  up) up ;;
  down) down ;;
  matrix)
    for combo in "${combos[@]}"; do
      read -r php laravel <<<"$combo"
      prepare "$php" "$laravel"
      run "$php" "$laravel" "fnreview-php:$php" sh -c '
        php -v | head -1; composer show laravel/framework orchestra/testbench phpunit/phpunit --format=text 2>/dev/null | grep -E "^versions" ;
        find src config database tests -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" ;
        vendor/bin/phpunit --no-progress --colors=never 2>&1 | tail -25;
        vendor/bin/phpstan analyse --no-progress --no-ansi 2>&1 | tail -4;
        for f in tests/smoke*.php; do php -d zend.assertions=1 -d assert.exception=1 $f; done' \
        | tee "$work/php$php-l$laravel.unit.log" || true
    done ;;
  services)
    php="${3:-8.4}"; laravel="${4:-13}"; prepare "$php" "$laravel"
    common=(-e IT_REDIS_HOST=fnreview-redis -e IT_REDIS_DB=9)
    run "$php" "$laravel" "${common[@]}" -e IT_DB_DRIVER=sqlite -e IT_SQLITE_PATH=/tmp/it.sqlite "fnreview-php:$php" sh -c 'touch /tmp/it.sqlite; vendor/bin/phpunit -c phpunit.integration.xml --colors=never --no-progress tests/Integration/DatabaseBackendTest.php 2>&1 | tail -40' | tee "$work/sqlite-$php-$laravel.log" || true
    run "$php" "$laravel" "${common[@]}" -e IT_DB_DRIVER=mysql -e IT_DB_HOST=fnreview-mysql -e IT_DB_PORT=3306 -e IT_DB_NAME=faultnest_it -e IT_DB_USER=root -e IT_DB_PASS=review "fnreview-php:$php" sh -c 'vendor/bin/phpunit -c phpunit.integration.xml --colors=never --no-progress tests/Integration/DatabaseBackendTest.php 2>&1 | tail -40' | tee "$work/mysql-$php-$laravel.log" || true
    run "$php" "$laravel" "${common[@]}" -e IT_DB_DRIVER=pgsql -e IT_DB_HOST=fnreview-pgsql -e IT_DB_PORT=5432 -e IT_DB_NAME=faultnest_it -e IT_DB_USER=postgres -e IT_DB_PASS=review "fnreview-php:$php" sh -c 'vendor/bin/phpunit -c phpunit.integration.xml --colors=never --no-progress tests/Integration/DatabaseBackendTest.php 2>&1 | tail -40' | tee "$work/pgsql-$php-$laravel.log" || true
    run "$php" "$laravel" "${common[@]}" "fnreview-php:$php" sh -c 'vendor/bin/phpunit -c phpunit.integration.xml --colors=never --no-progress tests/Integration/RedisBackendTest.php 2>&1 | tail -40' | tee "$work/redis-$php-$laravel.log" || true
    ;;
  lowest)
    # Oldest dependency versions the composer constraints allow.
    php="${3:-8.2}"; laravel="${4:-12}"; dir="$work/php$php-l$laravel-lowest"; mkdir -p "$dir"
    rsync -a --delete --exclude vendor --exclude node_modules --exclude composer.lock --exclude .git --exclude dist --exclude .phpstan "$root/" "$dir/"
    image "$php"
    docker run --rm -v "$dir:/app" -w /app "fnreview-php:$php" sh -c "composer update -q --no-interaction --prefer-dist --prefer-lowest --prefer-stable --with 'laravel/framework:^$laravel.0' 2>&1 | tail -20; composer show laravel/framework orchestra/testbench phpunit/phpunit 2>/dev/null | grep -E '^(name|versions)'; vendor/bin/phpunit --no-progress --colors=never 2>&1 | tail -25" | tee "$work/lowest-$php-$laravel.log" || true
    ;;
  predis)
    php="${3:-8.4}"; laravel="${4:-13}"; prepare "$php" "$laravel"; dir="$work/php$php-l$laravel"
    run "$php" "$laravel" -e IT_REDIS_HOST=fnreview-redis -e IT_REDIS_DB=9 -e IT_REDIS_CLIENT=predis "fnreview-php:$php" sh -c 'composer require -q --dev --no-interaction predis/predis; vendor/bin/phpunit -c phpunit.integration.xml --colors=never --no-progress tests/Integration/RedisBackendTest.php 2>&1 | tail -30' | tee "$work/predis-$php-$laravel.log" || true
    ;;
  *) echo "Unknown command: $cmd" >&2; exit 2 ;;
esac
