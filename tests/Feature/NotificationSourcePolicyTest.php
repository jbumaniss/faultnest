<?php

namespace FaultNest\Tests\Feature;

use FaultNest\Browser\BrowserEventReceiver;
use FaultNest\Contracts\{EventSinkInterface, FingerprintStrategyInterface, GroupReaderInterface, NotificationChannelInterface};
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\Facades\FaultNest;
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use FaultNest\Notifications\{Alert, ChannelSettings, DeliveryTarget};
use FaultNest\Notifications\Channels\MailChannel;
use FaultNest\Notifications\Jobs\SendAlertJob;
use FaultNest\Storage\Database\GroupQuery;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\{JobProcessing, Looping};
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Uses only Queue::fake() and in-memory stores; nothing is delivered. */
class NotificationSourcePolicyTest extends TestCase
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
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $this->app['db']->purge('sqlite');
        $config->set('queue.default', 'redis-like');
        $config->set('queue.connections.redis-like', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        $config->set('faultnest.enabled', true);
        $config->set('faultnest.project', 'policy-test');
        $config->set('faultnest.environment', 'testing');
        $config->set('faultnest.browser.enabled', true);
        $config->set('faultnest.storage.enabled', false);
        $config->set('faultnest.storage.database.enabled', true);
        $config->set('faultnest.storage.database.connection', 'sqlite');
        $config->set('faultnest.notifications.enabled', true);
        $config->set('faultnest.notifications.channels', ['mail' => $this->channel()]);
        $migration = require __DIR__.'/../../database/migrations/2026_11_04_000001_create_faultnest_history_tables.php';
        $migration->up();
        Queue::fake();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    protected function channel(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true, 'driver' => MailChannel::class, 'recipient_resolver' => null,
            'recipients' => ['one@example.test'], 'min_level' => 'error', 'cooldown_seconds' => 1800,
            'level_cooldowns' => [], 'pending_ttl_seconds' => 3600, 'tries' => 3, 'backoff_seconds' => 60,
            'cache_store' => 'array', 'queue_connection' => null, 'queue' => 'alerts',
            'allow_sync' => false, 'include_context' => false,
        ];
    }

    protected function browserEvent(string $message, string $source = 'browser'): \FaultNest\Domain\CaptureResult
    {
        return $this->app->make(BrowserEventReceiver::class)->accept([
            'schema' => 1, 'kind' => 'exception', 'source' => $source, 'level' => 'error',
            'message' => $message, 'exceptionType' => 'TypeError',
        ]);
    }

    /** @return list<string> */
    protected function queuedChannels(): array
    {
        return Queue::pushed(SendAlertJob::class)->map(fn ($job) => $job->channelClass === MailChannel::class ? 'mail' : 'ops')->values()->all();
    }

    public function test_browser_flood_is_grouped_and_stored_but_queues_no_notifications_by_default(): void
    {
        foreach (range('a', 'y') as $word) { // Digits would normalize into one group.
            self::assertSame(CaptureStatus::Captured, $this->browserEvent("distinct browser failure $word")->status);
        }
        Queue::assertNothingPushed();

        $groups = resolve(GroupReaderInterface::class)->paginate(new GroupQuery('policy-test', 'testing', perPage: 100));
        self::assertCount(25, $groups->items);
    }

    public function test_react_events_are_also_suppressed_by_default_but_still_captured(): void
    {
        self::assertSame(CaptureStatus::Captured, $this->browserEvent('react failure', 'react')->status);
        Queue::assertNothingPushed();
    }

    public function test_server_side_manual_events_still_notify_by_default(): void
    {
        FaultNest::captureMessage('server failure');
        Queue::assertPushed(SendAlertJob::class, 1);
    }

    public function test_browser_notifications_require_an_explicit_exact_source_opt_in(): void
    {
        $this->app['config']->set('faultnest.notifications.channels.mail.allowed_sources', ['browser']);

        $this->browserEvent('react stays off', 'react');
        Queue::assertNothingPushed();
        FaultNest::captureMessage('manual was removed from this channel');
        Queue::assertNothingPushed();

        $this->browserEvent('browser opted in');
        Queue::assertPushed(SendAlertJob::class, 1);
    }

    public function test_sources_are_isolated_per_channel_and_apply_to_any_channel_driver(): void
    {
        $this->app['config']->set('faultnest.notifications.channels', [
            'mail' => $this->channel(),
            'ops' => $this->channel(['driver' => SourcePolicyChannel::class, 'recipients' => ['ops-target'], 'allowed_sources' => ['browser', 'manual']]),
        ]);

        $this->browserEvent('reaches only the opted-in channel');
        self::assertSame(['ops'], $this->queuedChannels());

        FaultNest::captureMessage('reaches both channels');
        self::assertSame(['ops', 'mail', 'ops'], $this->queuedChannels());
    }

    public function test_a_suppressed_browser_event_reserves_nothing_for_the_same_group(): void
    {
        $this->app['config']->set('faultnest.grouping.driver', SamePolicyFingerprint::class);

        $this->browserEvent('same group from a browser');
        Queue::assertNothingPushed();

        FaultNest::captureMessage('same group from the server');
        Queue::assertPushed(SendAlertJob::class, 1);
    }

    public function test_an_empty_source_list_disables_alerts_for_that_channel(): void
    {
        $this->app['config']->set('faultnest.notifications.channels.mail.allowed_sources', []);
        FaultNest::captureMessage('nothing is allowed');
        Queue::assertNothingPushed();
    }

    public function test_settings_default_validate_and_decide_sources(): void
    {
        $settings = ChannelSettings::fromArray('mail', $this->channel());
        self::assertSame(['http', 'console', 'queue', 'manual'], $settings->allowedSources);

        foreach ([
            'text' => 'browser', 'map' => ['a' => 'browser'], 'number' => [1],
            'empty' => [''], 'uppercase' => ['Browser'], 'too long' => [str_repeat('a', 33)],
        ] as $name => $invalid) {
            try {
                ChannelSettings::fromArray('mail', $this->channel(['allowed_sources' => $invalid]));
                self::fail('Expected rejection: '.$name);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_published_config_defaults_exclude_browser_sources(): void
    {
        $config = require __DIR__.'/../../config/faultnest.php';
        self::assertSame(['http', 'console', 'queue', 'manual'], $config['notifications']['channels']['mail']['allowed_sources']);
    }

    public function test_automatic_reports_name_their_lifecycle_so_the_defaults_cover_them(): void
    {
        $sources = [];
        $sink = new class($sources) implements EventSinkInterface {
            /** @param list<string> $sources */
            public function __construct(public array &$sources) {}

            public function record(ErrorEvent $event): void
            {
                $this->sources[] = $event->source;
            }
        };
        $this->app['config']->set('faultnest.storage.database.enabled', false);
        $this->app['config']->set('faultnest.notifications.enabled', false);
        $this->app->instance(EventSinkInterface::class, $sink);
        $this->app->make(FaultNestManager::class)->init();
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->report(new RuntimeException('command failure'));

        $job = $this->createStub(Job::class);
        $this->app['events']->dispatch(new JobProcessing('redis-like', $job));
        $handler->report(new RuntimeException('job failure'));
        $this->app['events']->dispatch(new Looping('redis-like', 'default'));
        $handler->report(new RuntimeException('worker failure between jobs'));

        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $handler->report(new RuntimeException('request failure'));

        self::assertSame(['console', 'queue', 'console', 'http'], $sources);
    }
}

class SourcePolicyChannel implements NotificationChannelInterface
{
    public function send(Alert $alert, DeliveryTarget $target): void {}
}

class SamePolicyFingerprint implements FingerprintStrategyInterface
{
    public function fingerprint(ErrorEvent $event): string
    {
        return 'same-policy-group';
    }

    public function version(): int
    {
        return 1;
    }
}
