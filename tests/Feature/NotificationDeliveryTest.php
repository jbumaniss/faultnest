<?php

namespace FaultNest\Tests\Feature;

use DateTimeImmutable;
use FaultNest\Contracts\{NotificationChannelInterface, RecipientResolverInterface};
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\Facades\FaultNest;
use FaultNest\Laravel\FaultNestServiceProvider;
use FaultNest\Notifications\{Alert, CacheNotificationGate, DeliveryTarget, NotificationDeliveryException};
use FaultNest\Notifications\Channels\MailChannel;
use FaultNest\Notifications\Jobs\SendAlertJob;
use Illuminate\Support\Facades\{Cache, Mail, Queue};
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Uses only Laravel's array mail transport and fake/array queues; nothing leaves the process. */
class NotificationDeliveryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->app['config'];
        $config->set('cache.default', 'array');
        $config->set('mail.default', 'array');
        $config->set('queue.default', 'redis-like');
        $config->set('queue.connections.redis-like', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        $config->set('faultnest.enabled', true);
        $config->set('faultnest.project', 'mail-test');
        $config->set('faultnest.environment', 'testing');
        $config->set('faultnest.notifications.enabled', true);
        $config->set('faultnest.notifications.channels.mail', [
            'enabled' => true, 'driver' => MailChannel::class, 'recipient_resolver' => null,
            'recipients' => ['one@example.test'], 'min_level' => 'error', 'cooldown_seconds' => 1800,
            'level_cooldowns' => [], 'pending_ttl_seconds' => 3600, 'tries' => 3, 'backoff_seconds' => 60,
            'cache_store' => 'array', 'queue_connection' => null, 'queue' => 'alerts',
            'allow_sync' => false, 'include_context' => false,
        ]);
    }

    protected function channel(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->app['config']->set('faultnest.notifications.channels.mail.'.$key, $value);
        }
    }

    /** @return list<\Symfony\Component\Mailer\SentMessage> */
    protected function sent(): array
    {
        return array_values(iterator_to_array(Mail::mailer('array')->getSymfonyTransport()->messages()));
    }

    protected function runPushedJobs(): int
    {
        $count = 0;
        foreach (Queue::pushed(SendAlertJob::class) as $job) {
            unserialize(serialize($job))->handle();
            $count++;
        }
        return $count;
    }

    public function test_single_and_multiple_recipients_receive_independent_jobs_and_messages(): void
    {
        Queue::fake();
        $this->channel(['recipients' => ['one@example.test', 'TWO@example.test', 'two@example.test', 'not-an-email', "x\r\nBcc: evil@example.test"]]);
        self::assertSame(CaptureStatus::Captured, FaultNest::captureMessage('fail')->status);
        Queue::assertPushed(SendAlertJob::class, 2);
        Queue::assertPushedOn('alerts', SendAlertJob::class);
        self::assertSame(2, $this->runPushedJobs());

        $messages = $this->sent();
        self::assertCount(2, $messages);
        $recipients = array_map(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress(), $messages);
        self::assertSame(['one@example.test', 'TWO@example.test'], $recipients);
    }

    public function test_dynamic_resolver_decides_recipients(): void
    {
        Queue::fake();
        $this->channel(['recipient_resolver' => LevelBasedResolver::class, 'recipients' => ['configured@example.test']]);
        FaultNest::captureMessage('critical failure', ErrorLevel::Critical);
        Queue::assertPushed(SendAlertJob::class, 2);
        $targets = Queue::pushed(SendAlertJob::class)->map(fn ($job) => $job->target->value)->all();
        self::assertSame(['configured@example.test', 'oncall@example.test'], $targets);
    }

    public function test_level_filtering_and_level_specific_cooldown(): void
    {
        Queue::fake();
        FaultNest::captureMessage('only warning', ErrorLevel::Warning);
        Queue::assertNothingPushed();

        $this->channel(['level_cooldowns' => ['critical' => 5]]);
        $this->refreshManager();
        FaultNest::captureMessage('critical alert', ErrorLevel::Critical);
        self::assertSame(5, Queue::pushed(SendAlertJob::class)->first()->reservation->cooldownSeconds);
    }

    public function test_cooldown_blocks_only_the_delivered_recipient_and_starts_after_success(): void
    {
        Queue::fake();
        $this->channel(['recipients' => ['a@example.test', 'b@example.test']]);
        FaultNest::captureMessage('group');
        $jobs = Queue::pushed(SendAlertJob::class)->values();
        self::assertCount(2, $jobs);

        // Both pending: duplicate capture queues nothing.
        FaultNest::captureMessage('group');
        self::assertCount(2, Queue::pushed(SendAlertJob::class));

        $jobs[0]->handle(); // a delivered -> cooldown. b stays pending until released.
        $jobs[1]->failed(new RuntimeException('b failed'));
        FaultNest::captureMessage('group');
        $targets = Queue::pushed(SendAlertJob::class)->map(fn ($job) => $job->target->value)->values()->all();
        self::assertSame(['a@example.test', 'b@example.test', 'b@example.test'], $targets,
            'a must be in cooldown while b, whose delivery failed, may be alerted again.');
    }

    public function test_duplicate_or_parallel_execution_of_one_reservation_sends_once(): void
    {
        Queue::fake();
        FaultNest::captureMessage('dupe');
        $job = Queue::pushed(SendAlertJob::class)->first();
        $copy = unserialize(serialize($job));
        $job->handle();
        $copy->handle();
        self::assertCount(1, $this->sent());
    }

    public function test_transport_failure_retries_keep_reservation_and_final_failure_releases_without_cooldown(): void
    {
        Queue::fake();
        $this->app->bind(MailChannel::class, FailingChannel::class);
        FaultNest::captureMessage('flaky');
        $job = Queue::pushed(SendAlertJob::class)->first();
        self::assertSame(3, $job->tries);
        self::assertSame(60, $job->backoff);

        try {
            $job->handle();
            self::fail('Transport failure must be visible to the queue for retry.');
        } catch (NotificationDeliveryException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
        $gate = new CacheNotificationGate(Cache::store('array'));
        self::assertTrue($gate->owns($job->reservation), 'The retry must still own its lease.');

        FaultNest::captureMessage('flaky');
        self::assertCount(1, Queue::pushed(SendAlertJob::class), 'No duplicate while a retry is pending.');

        $job->failed(new RuntimeException('final'));
        self::assertFalse($gate->owns($job->reservation));
        FaultNest::captureMessage('flaky');
        self::assertCount(2, Queue::pushed(SendAlertJob::class), 'A failed delivery must not start a cooldown.');
    }

    public function test_expired_reservation_makes_the_late_job_a_noop(): void
    {
        Queue::fake();
        FaultNest::captureMessage('late');
        $job = Queue::pushed(SendAlertJob::class)->first();
        Cache::store('array')->forget($job->reservation->key.':pending');
        $job->handle();
        self::assertCount(0, $this->sent());
    }

    public function test_outbound_context_requires_explicit_channel_opt_in(): void
    {
        Queue::fake();
        FaultNest::captureMessage('private ctx', context: ['order' => 'ctx-value-1']);
        $this->runPushedJobs();
        self::assertStringNotContainsString('ctx-value-1', $this->sent()[0]->toString());

        Queue::fake();
        $this->channel(['include_context' => true]);
        $this->refreshManager();
        FaultNest::captureMessage('public ctx', context: ['order' => 'ctx-value-2']);
        $this->runPushedJobs();
        $messages = $this->sent();
        self::assertStringContainsString('ctx-value-2', end($messages)->toString());
    }

    public function test_disabled_notifications_or_channel_or_empty_recipients_queue_nothing(): void
    {
        Queue::fake();
        $this->app['config']->set('faultnest.notifications.enabled', false);
        FaultNest::captureMessage('off');
        Queue::assertNothingPushed();

        $this->refreshManager();
        $this->app['config']->set('faultnest.notifications.enabled', true);
        $this->channel(['enabled' => false]);
        FaultNest::captureMessage('channel off');
        Queue::assertNothingPushed();

        $this->refreshManager();
        $this->channel(['enabled' => true, 'recipients' => []]);
        FaultNest::captureMessage('no recipients');
        Queue::assertNothingPushed();
    }

    public function test_sync_queue_is_refused_unless_explicitly_allowed_and_never_breaks_capture(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        FaultNest::captureMessage('refused');
        self::assertCount(0, $this->sent());

        $this->refreshManager();
        $this->channel(['allow_sync' => true]);
        self::assertSame(CaptureStatus::Captured, FaultNest::captureMessage('allowed sync')->status);
        self::assertCount(1, $this->sent());
    }

    public function test_sync_delivery_failure_cannot_recurse_or_escape(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->channel(['allow_sync' => true]);
        $this->app->bind(MailChannel::class, FailingChannel::class);
        FailingChannel::$calls = 0;
        $result = FaultNest::captureMessage('sync failure');
        self::assertContains($result->status, [CaptureStatus::Captured, CaptureStatus::Failed]);
        self::assertSame(1, FailingChannel::$calls);
    }

    public function test_queue_dispatch_failure_releases_reservation(): void
    {
        $this->app['config']->set('queue.connections.redis-like.connection', 'missing-connection');
        FaultNest::captureMessage('dispatch fail');
        $event = new ErrorEvent(str_repeat('a', 32), 'mail-test', 'testing', 'manual', ErrorLevel::Error, null,
            'dispatch fail', new DateTimeImmutable(), null, null, fingerprint: 'x');
        self::assertNotNull((new CacheNotificationGate(Cache::store('array')))->reserve($event, 'mail', new DeliveryTarget('one@example.test'), 10, 60));
    }

    public function test_mail_subject_and_body_resist_header_injection(): void
    {
        $event = new ErrorEvent(str_repeat('a', 32), "proj\r\nBcc: evil@example.test", 'testing', 'manual', ErrorLevel::Error,
            "Type\r\nX-Injected: 1", "line\r\nX-Injected: 2", new DateTimeImmutable(), null, null, fingerprint: 'abcdef012345');
        $this->app->make(MailChannel::class)->send(new Alert($event), new DeliveryTarget('one@example.test'));
        $raw = $this->sent()[0]->toString();
        [$headers] = explode("\r\n\r\n", $raw, 2);
        self::assertStringNotContainsStringIgnoringCase("\r\nX-Injected", $headers);
        self::assertStringNotContainsStringIgnoringCase("\r\nBcc:", $headers);
    }

    protected function refreshManager(): void
    {
        $this->app->forgetInstance(\FaultNest\Laravel\FaultNestManager::class);
        FaultNest::clearResolvedInstances();
    }
}

class LevelBasedResolver implements RecipientResolverInterface
{
    public function resolve(ErrorEvent $event, array $configured): array
    {
        return $event->level === ErrorLevel::Critical ? [...$configured, 'oncall@example.test'] : $configured;
    }
}

class FailingChannel implements NotificationChannelInterface
{
    public static int $calls = 0;

    public function send(Alert $alert, DeliveryTarget $target): void
    {
        self::$calls++;
        throw new RuntimeException('smtp down');
    }
}
