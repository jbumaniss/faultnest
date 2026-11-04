<?php

namespace FaultNest\Storage;

use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\ErrorEvent;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use RuntimeException;

/** Bounded, atomic group counters in the installing application's cache store. */
class CacheGroupStore implements EventSinkInterface
{
    private const LOCK_RETRY_MILLISECONDS = 10;

    protected LockProvider $locks;

    public function __construct(
        protected Repository $cache,
        protected int $ttlSeconds = 86400,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 31536000) {
            throw new InvalidArgumentException('Invalid FaultNest group TTL.');
        }
        $store = $this->cache->getStore();
        if (!$store instanceof LockProvider) {
            throw new InvalidArgumentException('FaultNest grouping requires a cache store supporting atomic locks.');
        }
        $this->locks = $store;
    }

    public function record(ErrorEvent $event): void
    {
        $key = $this->key($event->project, $event->environment, $event->fingerprintVersion, $event->fingerprint);
        $lock = $this->locks->lock($key.':lock', 5);
        if ($lock instanceof Lock) {
            // Laravel's default 250ms retry gives up after four attempts and drops events under contention.
            $lock->betweenBlockedAttemptsSleepFor(self::LOCK_RETRY_MILLISECONDS);
        }
        $lock->block(1, function () use ($key, $event): void {
            $group = $this->read($key);
            if ($group === null) {
                $group = new GroupSnapshot(
                    GroupIdentity::id($event), $event->project, $event->environment,
                    $event->fingerprint, $event->fingerprintVersion, $event->category,
                    $event->message, $event->level, 0, $event->occurredAt, $event->occurredAt,
                );
            }
            $saved = $this->cache->put($key, (new GroupSnapshot(
                $group->id, $group->project, $group->environment, $group->fingerprint,
                $group->fingerprintVersion, $group->category, $group->title,
                $event->level->priority() > $group->maxLevel->priority() ? $event->level : $group->maxLevel,
                $group->occurrences + 1, $group->firstSeenAt,
                $event->occurredAt > $group->lastSeenAt ? $event->occurredAt : $group->lastSeenAt,
            ))->toCache(), $this->ttlSeconds);
            if (!$saved) {
                throw new RuntimeException('FaultNest group counter could not be saved.');
            }
        });
    }

    public function find(string $project, string $environment, int $version, string $fingerprint): ?GroupSnapshot
    {
        return $this->read($this->key($project, $environment, $version, $fingerprint));
    }

    protected function read(string $key): ?GroupSnapshot
    {
        $stored = $this->cache->get($key);
        return is_array($stored) ? GroupSnapshot::fromCache($stored) : null;
    }

    protected function key(string $project, string $environment, int $version, string $fingerprint): string
    {
        return GroupIdentity::cacheKey($project, $environment, $version, $fingerprint);
    }
}
