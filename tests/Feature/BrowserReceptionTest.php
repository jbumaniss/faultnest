<?php

namespace FaultNest\Tests\Feature;

use FaultNest\Browser\BrowserEventReceiver;
use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\FaultNestServiceProvider;
use Orchestra\Testbench\TestCase;

class BrowserReceptionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    public function test_disabled_receiver_ignores_untrusted_events(): void
    {
        $receiver = $this->app->make(BrowserEventReceiver::class);
        self::assertSame(CaptureStatus::Ignored, $receiver->accept($this->event())->status);
    }

    public function test_host_controlled_browser_receiver_uses_existing_monitor_pipeline(): void
    {
        $sink = new class implements EventSinkInterface {
            /** @var list<ErrorEvent> */
            public array $events = [];
            public function record(ErrorEvent $event): void { $this->events[] = $event; }
        };
        $this->app->bind(EventSinkInterface::class, fn () => $sink);
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.storage.enabled', false);
        $this->app['config']->set('faultnest.project', 'host-project');
        $this->app['config']->set('faultnest.environment', 'testing');
        $this->app['config']->set('faultnest.browser.enabled', true);
        $this->app['config']->set('faultnest.browser.max_level', 'error');

        $receiver = $this->app->make(BrowserEventReceiver::class);
        $result = $receiver->accept($this->event());
        self::assertSame(CaptureStatus::Captured, $result->status);
        self::assertSame('host-project', $result->event->project);
        self::assertSame('testing', $result->event->environment);
        self::assertSame(ErrorLevel::Error, $result->event->level);
        self::assertSame('browser', $result->event->source);
        self::assertSame([], $result->event->context);
        self::assertCount(1, $sink->events);

        self::assertSame(CaptureStatus::Failed, $receiver->accept($this->event() + ['project' => 'forged'])->status);
        self::assertSame(CaptureStatus::Ignored, $receiver->accept(array_replace($this->event(), ['level' => 'info']))->status);
        self::assertCount(1, $sink->events);
    }

    /** @return array<string, mixed> */
    protected function event(): array
    {
        return [
            'schema' => 1, 'kind' => 'exception', 'source' => 'browser',
            'level' => 'critical', 'message' => 'failed',
            'exceptionType' => 'TypeError',
        ];
    }
}
