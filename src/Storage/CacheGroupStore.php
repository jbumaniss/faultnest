<?php

namespace FaultNest\Storage;

use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\ErrorEvent;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use RuntimeException;

/** Bounded, atomic group counters in the installing application's cache store. */
class CacheGroupStore implements EventSinkInterface
{
    public function __construct(
        protected Repository $cache,
        protected int $ttlSeconds = 86400,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 31536000) {
            throw new InvalidArgumentException('Invalid FaultNest group TTL.');
        }
        if (!$this->cache->getStore() instanceof LockProvider) {
            throw new InvalidArgumentException('FaultNest grouping requires a cache store supporting atomic locks.');
        }
    }

    public function record(ErrorEvent $event): void
    {
        $key = $this->key($event->project, $event->environment, $event->fingerprintVersion, $event->fingerprint);
        $lock = $this->cache->getStore()->lock($key.':lock', 5);
        $lock->block(1, function () use ($key, $event): void {
            $group = $this->cache->get($key);
            if (!$group instanceof GroupSnapshot) {
                $group = new GroupSnapshot(
                    GroupIdentity::id($event), $event->project, $event->environment,
                    $event->fingerprint, $event->fingerprintVersion, $event->category,
                    $event->message, $event->level, 0, $event->occurredAt, $event->occurredAt,
                );
            }
            $saved = $this->cache->put($key, new GroupSnapshot(
                $group->id, $group->project, $group->environment, $group->fingerprint,
                $group->fingerprintVersion, $group->category, $group->title,
                $event->level->priority() > $group->maxLevel->priority() ? $event->level : $group->maxLevel,
                $group->occurrences + 1, $group->firstSeenAt,
                $event->occurredAt > $group->lastSeenAt ? $event->occurredAt : $group->lastSeenAt,
            ), $this->ttlSeconds);
            if (!$saved) {
                throw new RuntimeException('FaultNest group counter could not be saved.');
            }
        });
    }

    public function find(string $project, string $environment, int $version, string $fingerprint): ?GroupSnapshot
    {
        $stored = $this->cache->get($this->key($project, $environment, $version, $fingerprint));
        return $stored instanceof GroupSnapshot ? $stored : null;
    }

    protected function key(string $project, string $environment, int $version, string $fingerprint): string
    {
        return GroupIdentity::cacheKey($project, $environment, $version, $fingerprint);
    }
}
