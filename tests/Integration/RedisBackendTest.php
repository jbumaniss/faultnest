<?php

namespace FaultNest\Tests\Integration;

use DateTimeImmutable;
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use FaultNest\Notifications\{CacheNotificationGate, DeliveryTarget};
use FaultNest\Storage\{CacheGroupStore, GroupIdentity};
use Illuminate\Support\Facades\{Cache, Redis};
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Runs against the throwaway Redis in IT_REDIS_HOST; only that logical DB is flushed. */
class RedisBackendTest extends TestCase
{
    use IntegrationSupport;

    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $host = (string) getenv('IT_REDIS_HOST');
        if (!str_starts_with($host, 'fnreview-')) {
            throw new RuntimeException('Refusing to flush a non-review Redis host.');
        }
        $app['config']->set('database.redis.client', getenv('IT_REDIS_CLIENT') ?: 'phpredis');
        $app['config']->set('database.redis.default', ['host' => $host, 'port' => 6379, 'database' => (int) getenv('IT_REDIS_DB')]);
        $app['config']->set('database.redis.options.prefix', '');
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.prefix', 'itcache');
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('default')->flushdb();
    }

    protected function prefixed(string $key): string
    {
        return Cache::store('redis')->getStore()->getPrefix().$key;
    }

    protected function event(string $fingerprint = 'fp', ErrorLevel $level = ErrorLevel::Error): ErrorEvent
    {
        return new ErrorEvent(bin2hex(random_bytes(16)), 'it-project', 'it-env', 'manual', $level, null, 'failure',
            new DateTimeImmutable('now'), null, null, fingerprint: $fingerprint);
    }

    public function test_group_counter_ttl_and_lock_release(): void
    {
        $store = new CacheGroupStore(Cache::store('redis'), 600);
        $event = $this->event();
        $store->record($event);
        $store->record($this->event('fp', ErrorLevel::Critical));
        $group = $store->find('it-project', 'it-env', 1, 'fp');
        self::assertNotNull($group);
        self::assertSame(2, $group->occurrences);
        self::assertSame(ErrorLevel::Critical, $group->maxLevel);

        $key = $this->prefixed(GroupIdentity::cacheKey('it-project', 'it-env', 1, 'fp'));
        $ttl = Redis::connection('default')->ttl($key);
        self::assertGreaterThan(590, $ttl);
        self::assertLessThanOrEqual(600, $ttl);
        self::assertSame(0, (int) Redis::connection('default')->exists($key.':lock'), 'Group lock must be released.');
    }

    public function test_group_survives_laravel_13_serializable_classes_hardening(): void
    {
        // The Laravel 13 application skeleton ships cache.serializable_classes=false.
        $this->app['config']->set('cache.serializable_classes', false);
        Cache::forgetDriver('redis');
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.project', 'it-project');
        $this->app['config']->set('faultnest.environment', 'it-env');
        $manager = $this->app->make(FaultNestManager::class);
        $first = $manager->captureMessage('hardened failure');
        self::assertSame(CaptureStatus::Captured, $first->status);
        $manager->captureMessage('hardened failure');
        $manager->captureMessage('hardened failure');

        $group = $this->app->make(CacheGroupStore::class)->find('it-project', 'it-env', $first->event->fingerprintVersion, $first->event->fingerprint);
        self::assertNotNull($group, 'Group snapshot is unreadable with allowed_classes=false.');
        self::assertSame(3, $group->occurrences, 'Counter reset on every event.');
    }

    public function test_multi_process_group_counter_is_exact(): void
    {
        $result = $this->runWorkers('group', 8, 40, 'race', ['IT_REDIS_HOST' => getenv('IT_REDIS_HOST'), 'IT_REDIS_DB' => getenv('IT_REDIS_DB')]);
        self::assertSame(0, $result['failed'], 'Dropped under contention: '.json_encode($result));
        $group = (new CacheGroupStore(Cache::store('redis'), 600))->find('it-project', 'it-env', 1, 'race');
        self::assertSame(320, $group?->occurrences);
    }

    public function test_multi_process_reservation_has_exactly_one_winner(): void
    {
        $result = $this->runWorkers('gate', 8, 5, 'gate-race', ['IT_REDIS_HOST' => getenv('IT_REDIS_HOST'), 'IT_REDIS_DB' => getenv('IT_REDIS_DB')]);
        self::assertSame(1, $result['won'], json_encode($result));
    }

    public function test_reservation_ownership_lease_expiry_and_cooldown_ttl(): void
    {
        $gate = new CacheNotificationGate(Cache::store('redis'));
        $target = new DeliveryTarget('Person@example.test');
        $event = $this->event('gate');
        $connection = Redis::connection('default');

        $first = $gate->reserve($event, 'mail', $target, 120, 1);
        self::assertNotNull($first);
        self::assertTrue($gate->owns($first));
        self::assertNull($gate->reserve($event, 'mail', new DeliveryTarget('person@example.test'), 120, 1), 'Case variant must share a reservation.');
        self::assertNotNull($gate->reserve($event, 'mail', new DeliveryTarget('other@example.test'), 120, 60));

        sleep(2); // Lease expires for real.
        self::assertFalse($gate->owns($first));
        $second = $gate->reserve($event, 'mail', $target, 120, 60);
        self::assertNotNull($second);
        $gate->complete($first); // Stale owner cannot complete...
        self::assertSame(0, (int) $connection->exists($this->prefixed($first->key.':cooldown')));
        $gate->release($first); // ...or release the new lease.
        self::assertTrue($gate->owns($second));

        $gate->complete($second);
        $ttl = $connection->ttl($this->prefixed($second->key.':cooldown'));
        self::assertGreaterThan(110, $ttl);
        self::assertLessThanOrEqual(120, $ttl);
        self::assertSame(0, (int) $connection->exists($this->prefixed($second->key.':pending')));
        self::assertSame(0, (int) $connection->exists($this->prefixed($second->key.':mutex')));
        self::assertNull($gate->reserve($event, 'mail', $target, 120, 60), 'Cooldown must block.');
    }
}
