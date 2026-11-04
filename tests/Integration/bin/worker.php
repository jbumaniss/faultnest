<?php

// Isolated child process for real concurrency tests. Prints one JSON result line.
require __DIR__.'/../../../vendor/autoload.php';

use FaultNest\Domain\{ErrorEvent, ErrorLevel};
use FaultNest\Notifications\{CacheNotificationGate, DeliveryTarget};
use FaultNest\Storage\CacheGroupStore;
use FaultNest\Storage\Database\{DatabaseEventStore, HistorySettings};
use Illuminate\Cache\{RedisStore, Repository};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Redis\RedisManager;

[$_, $mode, $count, $fingerprint] = $argv;
$ok = 0;
$failed = 0;
$errors = [];
$event = fn (): ErrorEvent => new ErrorEvent(bin2hex(random_bytes(16)), 'it-project', 'it-env', 'manual', ErrorLevel::Error, null,
    'concurrent failure', new DateTimeImmutable('now', new DateTimeZone('UTC')), null, null, fingerprint: $fingerprint);

if ($mode === 'database') {
    $capsule = new Capsule();
    $capsule->addConnection(json_decode((string) getenv('IT_DB_CONFIG'), true, 16, JSON_THROW_ON_ERROR));
    $connection = $capsule->getConnection();
    $store = new DatabaseEventStore($connection, new HistorySettings(true, null, 30, (int) getenv('IT_SAMPLES')));
    $record = fn () => $store->record($event());
} else {
    $redis = new RedisManager(new Container(), getenv('IT_REDIS_CLIENT') ?: 'phpredis', ['default' => [
        'host' => getenv('IT_REDIS_HOST'), 'port' => 6379, 'database' => (int) getenv('IT_REDIS_DB'),
    ]]);
    $cache = new Repository(new RedisStore($redis, 'itcache'));
    if ($mode === 'group') {
        $store = new CacheGroupStore($cache, 600);
        $record = fn () => $store->record($event());
    } else {
        $gate = new CacheNotificationGate($cache);
        $record = function () use ($gate, $event, &$won): void {
            if ($gate->reserve($event(), 'mail', new DeliveryTarget('race@example.test'), 60, 60) !== null) {
                $won = ($won ?? 0) + 1;
            }
        };
    }
}

// Line up with sibling processes for maximum contention.
$start = (float) getenv('IT_START_AT');
while (microtime(true) < $start) {
    usleep(500);
}
for ($i = 0; $i < (int) $count; $i++) {
    try {
        $record();
        $ok++;
    } catch (Throwable $exception) {
        $failed++;
        $errors[$exception::class] = substr($exception->getMessage(), 0, 160);
    }
}
echo json_encode(['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'won' => $won ?? 0]), "\n";
