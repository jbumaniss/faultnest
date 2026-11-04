#!/usr/bin/env bash
# Runs inside the php container. Args: $1 = laravel major, $2 = db driver. Network: fnreview-net only.
set -euo pipefail
major="$1"; driver="$2"
db="host${major}"
cd /tmp
rm -rf host && composer create-project -q --no-interaction --prefer-dist "laravel/laravel:^${major}.0" host >/dev/null
cd host
composer config repositories.faultnest '{"type":"path","url":"/pkg","options":{"symlink":false}}'
composer config minimum-stability dev; composer config prefer-stable true
composer require -q --no-interaction "jbumaniss/faultnest:*@dev" predis/predis >/dev/null
php artisan package:discover --ansi 2>&1 | grep -i faultnest
cat >> .env <<E

FAULTNEST_ENABLED=true
FAULTNEST_PROJECT=e2e-host
FAULTNEST_DATABASE_ENABLED=true
FAULTNEST_BROWSER_ENABLED=true
FAULTNEST_NOTIFICATIONS_ENABLED=true
FAULTNEST_MAIL_ENABLED=true
FAULTNEST_MAIL_TO=dev-one@example.test,dev-two@example.test
FAULTNEST_MAIL_COOLDOWN=1800
E
sed -i -e "s/^DB_CONNECTION=.*/DB_CONNECTION=${driver}/" -e '/^# *DB_/s/^# *//' .env
if [ "$driver" = pgsql ]; then h=fnreview-pgsql; p=5432; u=postgres; else h=fnreview-mysql; p=3306; u=root; fi
sed -i -e "s/^DB_HOST=.*/DB_HOST=$h/" -e "s/^DB_PORT=.*/DB_PORT=$p/" -e "s/^DB_DATABASE=.*/DB_DATABASE=$db/" -e "s/^DB_USERNAME=.*/DB_USERNAME=$u/" -e "s/^DB_PASSWORD=.*/DB_PASSWORD=review/" .env
sed -i -e 's/^CACHE_STORE=.*/CACHE_STORE=redis/' -e 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' -e 's/^REDIS_HOST=.*/REDIS_HOST=fnreview-redis/' -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=array/' -e 's/^MAIL_MAILER=.*/MAIL_MAILER=log/' -e 's/^APP_ENV=.*/APP_ENV=e2e/' -e 's/^LOG_LEVEL=.*/LOG_LEVEL=debug/' .env
grep -q '^REDIS_DB=' .env && sed -i "s/^REDIS_DB=.*/REDIS_DB=${major}/" .env || echo "REDIS_DB=${major}" >> .env
echo "REDIS_CACHE_DB=${major}" >> .env
php -r '$r=new Redis;$r->connect("fnreview-redis");foreach([0,1,(int)$argv[1]] as $d){$r->select($d);$r->flushDB();}' "$major"
if [ "$driver" = pgsql ]; then php -r '$p=new PDO("pgsql:host=fnreview-pgsql;dbname=postgres","postgres","review");$p->exec("DROP DATABASE IF EXISTS $argv[1]");$p->exec("CREATE DATABASE $argv[1]");' "$db"
else php -r '$p=new PDO("mysql:host=fnreview-mysql","root","review");$p->exec("DROP DATABASE IF EXISTS $argv[1]");$p->exec("CREATE DATABASE $argv[1]");' "$db"; fi
# Host-side wiring exactly as the README instructs.
php -r '
$f="app/Providers/AppServiceProvider.php"; $s=file_get_contents($f);
$s=preg_replace("/public function boot\(\): void\s*\{\s*\/\/\s*\}/", "public function boot(): void\n    {\n        \\\\FaultNest\\\\Laravel\\\\Facades\\\\FaultNest::init();\n    }", $s, 1, $n);
if ($n!==1) { fwrite(STDERR,"provider patch failed\n"); exit(1);} file_put_contents($f,$s);
$b="bootstrap/app.php"; $s=file_get_contents($b);
$s=preg_replace("/->withMiddleware\(function \(Middleware \\\$middleware\)(: void)? \{/", "\$0\n        \\\$middleware->validateCsrfTokens(except: [\"e2e/errors\"]);", $s, 1, $n);
if ($n!==1) { fwrite(STDERR,"csrf patch failed\n"); exit(1);} file_put_contents($b,$s);'
cat /e2e/routes.php | sed '1d' >> routes/web.php
sed -i '1a use FaultNest\\Browser\\BrowserEventReceiver; use FaultNest\\Contracts\\{EventReaderInterface, GroupReaderInterface}; use FaultNest\\Domain\\CaptureStatus; use FaultNest\\Storage\\Database\\{EventQuery, GroupQuery}; use Illuminate\\Http\\Request;' routes/web.php
sed -i '/^use FaultNest\\Browser\\BrowserEventReceiver;$/d;/^use FaultNest\\Contracts\\{EventReaderInterface, GroupReaderInterface};$/d;/^use FaultNest\\Domain\\CaptureStatus;$/d;/^use FaultNest\\Storage\\Database\\{EventQuery, GroupQuery};$/d;/^use Illuminate\\Http\\Request;$/d;0,/^use Illuminate\\Support\\Facades\\Route;$/!{/^use Illuminate\\Support\\Facades\\Route;$/d}' routes/web.php
mkdir -p app/Jobs && cp /e2e/E2eFailingJob.php app/Jobs/
sed '1,2d' /e2e/console.php | sed '/^use /d' >> routes/console.php
sed -i '1a use App\\Jobs\\E2eFailingJob;' routes/console.php
cp /e2e-assets/e2e-page.html /e2e-assets/e2e-bundle.js . 2>/dev/null || true
echo "serializable_classes: $(grep -n serializable_classes config/cache.php || echo absent)"
php artisan migrate --force --no-interaction 2>&1 | grep -iE 'faultnest|error' || true
php artisan vendor:publish --tag=faultnest-config --force >/dev/null && test -f config/faultnest.php && echo publish-ok
php artisan config:cache >/dev/null && echo config-cache-ok
php artisan route:list --except-vendor 2>/dev/null | grep -c . || true
php artisan route:list 2>/dev/null | grep -i faultnest && echo "ROUTE LEAK" || echo no-faultnest-routes
php artisan route:cache >/dev/null && echo route-cache-ok && php artisan route:clear >/dev/null
echo READY
