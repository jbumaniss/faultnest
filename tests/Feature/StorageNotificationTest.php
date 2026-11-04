<?php

namespace FaultNest\Tests\Feature;

use DateTimeImmutable;
use FaultNest\Contracts\{NotificationChannelInterface, RecipientResolverInterface};
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use FaultNest\Notifications\{Alert, CacheNotificationGate, DeliveryTarget};
use FaultNest\Storage\CacheGroupStore;
use Orchestra\Testbench\TestCase;

class StorageNotificationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    public function test_group_summary_is_atomic_on_supported_host_store_without_context(): void
    {
        $store = new CacheGroupStore($this->app->make('cache')->store('array'), 600);
        $event = $this->event();
        $store->record($event);
        $store->record($event);

        $group = $store->find($event->project, $event->environment, 1, $event->fingerprint);
        self::assertNotNull($group);
        self::assertSame(2, $group->occurrences);
        self::assertSame('failure', $group->title);
        self::assertFalse(property_exists($group, 'context'));
        self::assertNull($store->find('other-project', 'production', 1, $event->fingerprint));
    }

    public function test_reservations_are_independent_per_recipient_and_cooldown_starts_after_success(): void
    {
        $gate = new CacheNotificationGate($this->app->make('cache')->store('array'));
        $event = $this->event();
        $alice = new DeliveryTarget('alice@example.com');
        $bob = new DeliveryTarget('bob@example.com');

        $first = $gate->reserve($event, 'mail', $alice, 1800, 3600);
        self::assertNotNull($first);
        self::assertNull($gate->reserve($event, 'mail', $alice, 1800, 3600));
        self::assertNotNull($gate->reserve($event, 'mail', $bob, 1800, 3600));
        $gate->release($first);
        self::assertNotNull($again = $gate->reserve($event, 'mail', $alice, 1800, 3600));
        $gate->release($first); // old owner cannot release a newer reservation
        self::assertTrue($gate->owns($again));
        $gate->complete($again);
        self::assertFalse($gate->owns($again));
        self::assertNull($gate->reserve($event, 'mail', $alice, 1800, 3600));
    }

    public function test_explicit_channel_is_queued_and_deduplicated_per_resolved_target(): void
    {
        $channel = new RecordingChannel();
        $this->app->instance(RecordingChannel::class, $channel);
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.project', 'test');
        $this->app['config']->set('faultnest.storage.enabled', true);
        $this->app['config']->set('faultnest.notifications.enabled', true);
        $this->app['config']->set('faultnest.notifications.channels', [
            'custom' => [
                'enabled' => true,
                'driver' => RecordingChannel::class,
                'recipient_resolver' => StaticRecipientResolver::class,
                'recipients' => ['fallback@example.com'],
                'min_level' => 'error',
                'cooldown_seconds' => 1800,
                'pending_ttl_seconds' => 3600,
                'tries' => 3,
                'backoff_seconds' => 60,
                'allow_sync' => true,
            ],
        ]);
        $this->app['config']->set('queue.default', 'sync');
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();

        self::assertSame(CaptureStatus::Captured, $manager->captureMessage('failure')->status);
        self::assertSame(CaptureStatus::Captured, $manager->captureMessage('failure')->status);
        self::assertSame(['alice@example.com', 'bob@example.com'], $channel->targets);
        $this->assertNotNull($this->app->make(CacheGroupStore::class));
    }

    public function test_disabled_notifications_do_not_dispatch_anything(): void
    {
        $channel = new RecordingChannel();
        $this->app->instance(RecordingChannel::class, $channel);
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.notifications.enabled', false);
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        self::assertSame(CaptureStatus::Captured, $manager->captureMessage('failure')->status);
        self::assertSame([], $channel->targets);
    }

    private function event(): ErrorEvent
    {
        return new ErrorEvent(
            'event-one', 'test', 'production', 'manual', ErrorLevel::Error,
            null, 'failure', new DateTimeImmutable('2026-11-04T12:00:00+00:00'),
            null, null, context: ['caller' => 'provided'], fingerprint: 'fingerprint',
        );
    }
}

class RecordingChannel implements NotificationChannelInterface
{
    /** @var list<string> */
    public array $targets = [];

    public function send(Alert $alert, DeliveryTarget $target): void
    {
        $this->targets[] = $target->value;
    }
}

class StaticRecipientResolver implements RecipientResolverInterface
{
    public function resolve(ErrorEvent $event, array $configured): array
    {
        return ['alice@example.com', 'alice@example.com', 'bob@example.com'];
    }
}
