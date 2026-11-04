<?php

namespace FaultNest\Notifications\Jobs;

use FaultNest\Contracts\NotificationChannelInterface;
use FaultNest\Notifications\{Alert, CacheNotificationGate, DeliveryTarget, NotificationDeliveryException, Reservation};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Async delivery: transport failures retry under host Laravel Queue policy. */
class SendAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Alert $alert,
        public DeliveryTarget $target,
        public string $channelClass,
        public Reservation $reservation,
        public ?string $cacheStore,
        public int $tries = 3,
        public int $backoff = 60,
    ) {}

    public function handle(): void
    {
        try {
            $gate = new CacheNotificationGate(Cache::store($this->cacheStore));
            if (!$gate->owns($this->reservation)) {
                return;
            }
            $channel = resolve($this->channelClass);
            if (!$channel instanceof NotificationChannelInterface) {
                throw new RuntimeException('Invalid FaultNest delivery channel.');
            }
            $channel->send($this->alert, $this->target);
            $gate->complete($this->reservation);
        } catch (Throwable $exception) {
            throw new NotificationDeliveryException('FaultNest notification transport failed.', previous: $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            (new CacheNotificationGate(Cache::store($this->cacheStore)))->release($this->reservation);
        } catch (Throwable) {
            // The lease will expire; never recursively report a FaultNest failure.
        }
    }
}
