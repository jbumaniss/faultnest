<?php

namespace FaultNest\Notifications;

use FaultNest\Domain\ErrorEvent;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use RuntimeException;

/** Serializes reservation/cooldown transitions using the host's atomic cache locks. */
class CacheNotificationGate
{
    public function __construct(protected Repository $cache)
    {
        if (!$this->cache->getStore() instanceof LockProvider) {
            throw new InvalidArgumentException('FaultNest notification cooldown requires atomic cache locks.');
        }
    }

    public function reserve(
        ErrorEvent $event,
        string $channel,
        DeliveryTarget $target,
        int $cooldownSeconds,
        int $leaseSeconds,
    ): ?Reservation {
        $key = 'faultnest:notification:v1:'.hash('sha256', json_encode([
            $event->project, $event->environment, $event->fingerprintVersion,
            $event->fingerprint, $channel, strtolower($target->value),
        ], JSON_THROW_ON_ERROR));
        $token = bin2hex(random_bytes(16));

        return $this->withLock($key, function () use ($key, $token, $cooldownSeconds, $leaseSeconds): ?Reservation {
            if ($this->cache->get($key.':pending') !== null || $this->cache->get($key.':cooldown') !== null) {
                return null;
            }
            if (!$this->cache->put($key.':pending', $token, $leaseSeconds)) {
                throw new RuntimeException('FaultNest pending delivery could not be reserved.');
            }
            return new Reservation($key, $token, $cooldownSeconds);
        });
    }

    public function owns(Reservation $reservation): bool
    {
        return $this->cache->get($reservation->key.':pending') === $reservation->token
            && $this->cache->get($reservation->key.':cooldown') === null;
    }

    public function complete(Reservation $reservation): void
    {
        $this->withLock($reservation->key, function () use ($reservation): void {
            if ($this->cache->get($reservation->key.':pending') !== $reservation->token) {
                return;
            }
            if ($reservation->cooldownSeconds > 0) {
                if (!$this->cache->put($reservation->key.':cooldown', true, $reservation->cooldownSeconds)) {
                    throw new RuntimeException('FaultNest notification cooldown could not be saved.');
                }
            }
            $this->cache->forget($reservation->key.':pending');
        });
    }

    public function release(Reservation $reservation): void
    {
        $this->withLock($reservation->key, function () use ($reservation): void {
            if ($this->cache->get($reservation->key.':pending') === $reservation->token) {
                $this->cache->forget($reservation->key.':pending');
            }
        });
    }

    protected function withLock(string $key, callable $callback): mixed
    {
        return $this->cache->getStore()->lock($key.':mutex', 5)->block(1, $callback);
    }
}
