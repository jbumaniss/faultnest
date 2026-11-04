<?php

namespace FaultNest\Tests\Feature;

use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\{CaptureStatus, ErrorEvent};
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use Illuminate\Contracts\Debug\ExceptionHandler;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    public function test_opt_in_init_registers_once_without_disabling_laravel_reporter(): void
    {
        $sink = new class implements EventSinkInterface {
            public array $events = [];
            public function record(ErrorEvent $event): void { $this->events[] = $event; }
        };
        $this->app->bind(EventSinkInterface::class, fn () => $sink);
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.project', 'test-project');
        $this->app['config']->set('faultnest.environment', 'testing');

        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        $manager->init();
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('integration smoke'));

        self::assertCount(1, $sink->events);
        self::assertSame('laravel', $sink->events[0]->source);
        self::assertSame([], $sink->events[0]->context);
    }

    public function test_disabled_package_captures_nothing(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        self::assertSame(CaptureStatus::Ignored, $manager->captureMessage('disabled')->status);
    }

    public function test_scoped_context_resets_between_application_lifecycles(): void
    {
        $this->app['config']->set('faultnest.enabled', true);
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        $manager->setContext('explicit', 'first');
        self::assertSame(['explicit' => 'first'], $manager->captureMessage('one')->event->context);

        $this->app->forgetScopedInstances();
        self::assertSame([], $manager->captureMessage('two')->event->context);
    }
}
