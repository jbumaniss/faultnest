<?php

namespace FaultNest\Notifications;

use FaultNest\Contracts\{EventSinkInterface, NotificationChannelInterface, RecipientResolverInterface};
use FaultNest\Domain\ErrorEvent;
use FaultNest\Notifications\Channels\MailChannel;
use FaultNest\Notifications\Jobs\SendAlertJob;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/** Routes explicitly enabled channels through host-owned queue and cache. */
class NotificationEventSink implements EventSinkInterface
{
    /** @param list<ChannelSettings> $channels */
    public function __construct(
        protected Container $container,
        protected CacheManager $cache,
        protected Dispatcher $bus,
        protected array $channels,
    ) {}

    public function record(ErrorEvent $event): void
    {
        $lastFailure = null;
        $succeeded = false;
        foreach ($this->channels as $settings) {
            if (!$settings->accepts($event)) {
                continue;
            }
            try {
                $this->route($event, $settings);
                $succeeded = true;
            } catch (Throwable $exception) {
                $lastFailure = $exception;
            }
        }
        if (!$succeeded && $lastFailure !== null) {
            throw $lastFailure;
        }
    }

    protected function route(ErrorEvent $event, ChannelSettings $settings): void
    {
        $queueConnection = $settings->queueConnection
            ?? $this->container->make('config')->get('queue.default');
        $driver = $this->container->make('config')->get('queue.connections.'.$queueConnection.'.driver');
        if (!$settings->allowSync && (!is_string($driver) || in_array($driver, ['sync', 'background', 'deferred'], true))) {
            throw new InvalidArgumentException('FaultNest requires an asynchronous host queue, unless allow_sync is explicitly enabled.');
        }
        $recipientResolver = $settings->recipientResolver === null
            ? new DefaultRecipientResolver()
            : $this->container->make($settings->recipientResolver);
        if (!$recipientResolver instanceof RecipientResolverInterface) {
            throw new InvalidArgumentException('FaultNest channel recipient resolver must implement RecipientResolverInterface.');
        }
        $channel = $this->container->make($settings->driver);
        if (!$channel instanceof NotificationChannelInterface) {
            throw new InvalidArgumentException('FaultNest channel must implement NotificationChannelInterface.');
        }
        $targets = $recipientResolver->resolve($event, $settings->recipients);
        $unique = [];
        $gate = new CacheNotificationGate($this->cache->store($settings->cacheStore));
        foreach ($targets as $recipient) {
            if (!is_string($recipient)) {
                continue;
            }
            $recipient = trim($recipient);
            if ($recipient === '' || strlen($recipient) > 320 || strpbrk($recipient, "\r\n\0") !== false) {
                continue;
            }
            if ($channel instanceof MailChannel && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $canonical = strtolower($recipient);
            if (isset($unique[$canonical])) {
                continue;
            }
            $unique[$canonical] = true;
            $target = new DeliveryTarget($recipient);
            $reservation = $gate->reserve(
                $event, $settings->name, $target, $settings->cooldownFor($event->level), $settings->pendingTtlSeconds,
            );
            if ($reservation === null) {
                continue;
            }
            try {
                $job = new SendAlertJob(
                    new Alert($event, $settings->includeContext, $settings->includeTags), $target, $settings->driver,
                    $reservation, $settings->cacheStore, $settings->tries, $settings->backoffSeconds,
                );
                $job->onConnection($settings->queueConnection)->onQueue($settings->queue);
                $this->bus->dispatch($job);
            } catch (Throwable $exception) {
                $gate->release($reservation);
                throw $exception;
            }
        }
    }
}
