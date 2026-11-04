<?php

// Dependency-free state-machine smoke test. These tiny doubles are NOT Laravel
// compatibility tests: run Testbench tests with Composer for real integration.
namespace Illuminate\Contracts\Cache {
    interface LockProvider
    {
        public function lock(string $name, int $seconds = 0, ?string $owner = null): mixed;
        public function restoreLock(string $name, string $owner): mixed;
    }
}

namespace Illuminate\Cache {
    class Repository
    {
        /** @var array<string, mixed> */
        private array $values = [];

        public function __construct(private object $store) {}
        public function getStore(): object { return $this->store; }
        public function get(string $key): mixed { return $this->values[$key] ?? null; }
        public function put(string $key, mixed $value, int $ttl): bool {
            $this->values[$key] = $value;
            return true;
        }
        public function forget(string $key): bool {
            unset($this->values[$key]);
            return true;
        }
    }
}

namespace {
    spl_autoload_register(function (string $class): void {
        $prefix = 'FaultNest\\';
        if (str_starts_with($class, $prefix)) {
            require __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    });

    use FaultNest\Domain\{ErrorEvent, ErrorLevel};
    use FaultNest\Notifications\{CacheNotificationGate, DeliveryTarget};
    use FaultNest\Storage\CacheGroupStore;
    use Illuminate\Cache\Repository;
    use Illuminate\Contracts\Cache\LockProvider;

    $store = new class implements LockProvider {
        public function lock(string $name, int $seconds = 0, ?string $owner = null): mixed {
            return new class {
                public function block(int $seconds, callable $callback): mixed { return $callback(); }
            };
        }
        public function restoreLock(string $name, string $owner): mixed { return $this->lock($name); }
    };
    $cache = new Repository($store);
    $event = new ErrorEvent(
        'event-id', 'smoke', 'production', 'manual', ErrorLevel::Error, 'ExampleException',
        'sanitized failure', new DateTimeImmutable('2026-11-04T00:00:00+00:00'),
        'app/Service.php', 24, context: ['manual' => ['id' => 123]], fingerprint: 'group1',
    );
    $groups = new CacheGroupStore($cache, 3600);
    $groups->record($event);
    $groups->record($event);
    $summary = $groups->find('smoke', 'production', 1, 'group1');
    assert($summary?->occurrences === 2);
    assert($summary?->title === 'sanitized failure');
    assert(!property_exists($summary, 'context'));

    $gate = new CacheNotificationGate($cache);
    $alice = new DeliveryTarget('alice@example.com');
    $bob = new DeliveryTarget('bob@example.com');
    $reservation = $gate->reserve($event, 'mail', $alice, 1800, 3600);
    assert($reservation !== null);
    assert($gate->owns($reservation));
    assert($gate->reserve($event, 'mail', $alice, 1800, 3600) === null);
    assert($gate->reserve($event, 'mail', $bob, 1800, 3600) !== null);
    $gate->release($reservation);
    $second = $gate->reserve($event, 'mail', $alice, 1800, 3600);
    assert($second !== null && $second->token !== $reservation->token);
    $gate->release($reservation);
    assert($gate->owns($second));
    $gate->complete($second);
    assert(!$gate->owns($second));
    assert($gate->reserve($event, 'mail', $alice, 1800, 3600) === null);
    echo "PASS: cache group summary, reservation lifecycle, per-recipient isolation (doubled cache)\n";
}
