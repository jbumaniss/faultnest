<?php

namespace FaultNest\Tests\Feature;

use ErrorException;
use FaultNest\Capture\CaptureScope;
use FaultNest\Contracts\{CategoryClassifierInterface, EventFilterInterface, EventSinkInterface, EventTransformerInterface, FingerprintStrategyInterface};
use FaultNest\Domain\{Breadcrumb, CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\Facades\FaultNest;
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use FaultNest\Notifications\Jobs\SendAlertJob;
use FaultNest\Notifications\NotificationDeliveryException;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Console\Kernel as FoundationConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\{Artisan, Event, Http, Mail, Queue, Route, Schema};
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** Real Laravel HTTP/Console/Queue paths, not direct monitor calls. */
class LifecycleReportingTest extends TestCase
{
    protected RecordingSink $sink;

    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = new RecordingSink();
        $this->app->instance(EventSinkInterface::class, $this->sink);
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.project', 'lifecycle');
        $this->app['config']->set('faultnest.environment', 'testing');
        $this->app['config']->set('faultnest.storage.enabled', false);
    }

    public function test_service_provider_registers_services_without_routes_or_activation(): void
    {
        self::assertInstanceOf(FaultNestManager::class, $this->app->make(FaultNestManager::class));
        self::assertTrue($this->app->bound(CaptureScope::class));
        self::assertSame('lifecycle', $this->app['config']->get('faultnest.project'));
        foreach (Route::getRoutes()->getRoutes() as $route) {
            self::assertStringNotContainsString('FaultNest', (string) $route->getActionName(), 'The package must own no HTTP routes.');
            self::assertStringNotContainsStringIgnoringCase('faultnest', $route->uri());
        }
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('before init'));
        self::assertSame([], $this->sink->events, 'Provider discovery alone must not capture.');
    }

    public function test_migrations_and_prune_command_stay_unregistered_until_history_opt_in(): void
    {
        self::assertArrayNotHasKey('faultnest:prune', Artisan::all());
        self::assertFalse(Schema::hasTable('faultnest_groups'));
    }

    public function test_http_exception_is_reported_once_and_laravel_response_and_log_are_unchanged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message;
        });
        Route::get('/fail', fn () => throw new RuntimeException('http failure'));
        FaultNest::init();
        FaultNest::init();

        $this->withHeaders(['Authorization' => 'Bearer top-secret', 'Cookie' => 'session=private'])
            ->get('/fail?email=person@example.test')->assertStatus(500);

        self::assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        self::assertSame(['http failure'], $logged, 'Laravel must still log the exception exactly once.');
        self::assertSame([], $event->context);
        self::assertSame([], $event->tags);
        $encoded = json_encode($event);
        foreach (['top-secret', 'session=private', 'person@example.test', '127.0.0.1', '/fail'] as $private) {
            self::assertStringNotContainsString($private, (string) $encoded);
        }
    }

    public function test_console_exception_is_reported_once(): void
    {
        FaultNest::init();

        // Testbench's console kernel rethrows; Laravel's real kernel is what reports.
        $kernel = new FoundationConsoleKernel($this->app, $this->app['events']);
        $kernel->registerCommand(new ClosureCommand('faultnest-test:fail', function (): void {
            throw new RuntimeException('console failure');
        }));
        $status = $kernel
            ->handle(new ArrayInput(['command' => 'faultnest-test:fail']), new NullOutput());

        self::assertSame(1, $status);
        self::assertCount(1, $this->sink->events);
        self::assertSame('console failure', $this->sink->events[0]->message);
    }

    public function test_queue_worker_reports_job_exception_and_resets_scope_between_jobs(): void
    {
        $this->useDatabaseQueue();
        FaultNest::init();

        ScopedFailingJob::dispatch('first');
        ScopedFailingJob::dispatch('second');
        for ($i = 0; $i < 2; $i++) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--stop-when-empty' => true]);
        }

        self::assertCount(2, $this->sink->events);
        self::assertSame(['job' => 'first'], $this->sink->events[0]->context);
        self::assertSame(['job' => 'second'], $this->sink->events[1]->context, 'Context leaked between queue jobs.');
    }

    public function test_manual_capture_exception_and_message(): void
    {
        FaultNest::setTag('team', 'orders');
        FaultNest::setContext('order', ['id' => 7]);
        FaultNest::addBreadcrumb(new Breadcrumb('step', ['n' => 1]));

        $exception = FaultNest::captureException(new RuntimeException('manual'), ['extra' => true], ErrorLevel::Critical);
        self::assertSame(CaptureStatus::Captured, $exception->status);
        self::assertSame('manual', $exception->event->source);
        self::assertSame(ErrorLevel::Critical, $exception->event->level);
        self::assertSame(['order' => ['id' => 7], 'extra' => true], $exception->event->context);
        self::assertSame(['team' => 'orders'], $exception->event->tags);
        self::assertSame([['message' => 'step', 'data' => ['n' => 1]]], $exception->event->breadcrumbs);

        $message = FaultNest::captureMessage('note', ErrorLevel::Warning, ['k' => 'v']);
        self::assertSame(CaptureStatus::Captured, $message->status);
        self::assertNull($message->event->exceptionType);
        self::assertSame(CaptureStatus::Ignored, FaultNest::captureMessage('below minimum', ErrorLevel::Info)->status);
    }

    public function test_severity_uses_error_exception_type_and_explicit_override(): void
    {
        $warning = FaultNest::captureException(new ErrorException('w', 0, E_WARNING));
        $fatal = FaultNest::captureException(new ErrorException('e', 0, E_ERROR));
        self::assertSame(ErrorLevel::Warning, $warning->event->level);
        self::assertSame(ErrorLevel::Error, $fatal->event->level);
    }

    public function test_filter_transformer_classifier_fingerprint_are_container_resolved_and_applied(): void
    {
        $config = $this->app['config'];
        $config->set('faultnest.filters', [DropMarkedFilter::class]);
        $config->set('faultnest.transformers', [MaskingTransformer::class]);
        $config->set('faultnest.classification.driver', FixedClassifier::class);
        $config->set('faultnest.grouping.driver', FixedFingerprint::class);

        self::assertSame(CaptureStatus::Ignored, FaultNest::captureMessage('drop me')->status);
        $event = FaultNest::captureMessage('keep me')->event;
        self::assertSame('masked: keep me', $event->message);
        self::assertSame('fixed-category', $event->category);
        self::assertSame('fixed-fingerprint', $event->fingerprint);
        self::assertSame(9, $event->fingerprintVersion);
    }

    public function test_default_classifier_and_fingerprint_group_variable_values(): void
    {
        $a = FaultNest::captureMessage('Order 123 for 0a1b2c3d-1111-2222-3333-444455556666 failed')->event;
        $b = FaultNest::captureMessage('order 456 for 9f8e7d6c-1111-2222-3333-444455556666   failed')->event;
        $c = FaultNest::captureMessage('A different failure')->event;
        self::assertSame($a->fingerprint, $b->fingerprint);
        self::assertNotSame($a->fingerprint, $c->fingerprint);
        self::assertSame('application', $a->category);
        self::assertSame('database', FaultNest::captureException(new \PDOException('x'))->event->category);
    }

    public function test_ignored_exception_classes_include_subclasses(): void
    {
        $this->app['config']->set('faultnest.ignore_exceptions', [RuntimeException::class]);
        self::assertSame(CaptureStatus::Ignored, FaultNest::captureException(new NotificationDeliveryException('sub'))->status);
        self::assertSame(CaptureStatus::Captured, FaultNest::captureException(new \LogicException('other'))->status);
    }

    public function test_invalid_configuration_never_breaks_the_host_and_reports_failed(): void
    {
        $this->app['config']->set('faultnest.min_level', 'not-a-level');
        FaultNest::init();
        self::assertSame(CaptureStatus::Failed, FaultNest::captureMessage('x')->status);
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('still fine'));
        self::assertSame([], $this->sink->events);
    }

    public function test_failing_extensions_and_sinks_never_escape_the_reporter(): void
    {
        $logged = 0;
        Event::listen(MessageLogged::class, function () use (&$logged): void { $logged++; });
        $this->app['config']->set('faultnest.transformers', [ThrowingTransformer::class]);
        FaultNest::init();

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('transformer throws'));
        self::assertSame(1, $logged, 'Host logging must survive monitor failure.');
        self::assertSame(CaptureStatus::Failed, FaultNest::captureMessage('x')->status);
        self::assertSame([], $this->sink->events);
    }

    public function test_reentrant_report_from_a_sink_does_not_recurse(): void
    {
        $sink = new class($this->app) implements EventSinkInterface {
            public int $calls = 0;
            public function __construct(private $app) {}
            public function record(ErrorEvent $event): void
            {
                $this->calls++;
                $this->app->make(ExceptionHandler::class)->report(new RuntimeException('from sink'));
            }
        };
        $this->app->instance(EventSinkInterface::class, $sink);
        FaultNest::init();
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('outer'));
        self::assertSame(1, $sink->calls);
    }

    public function test_no_external_traffic_or_queue_work_with_default_opt_in_config(): void
    {
        Http::fake();
        Mail::fake();
        Queue::fake();
        FaultNest::init();
        self::assertSame(CaptureStatus::Captured, FaultNest::captureException(new RuntimeException('local only'))->status);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_disabled_package_does_not_register_a_reporter(): void
    {
        $this->app['config']->set('faultnest.enabled', false);
        FaultNest::init();
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('disabled'));
        self::assertSame([], $this->sink->events);
    }

    public function test_capture_exceptions_toggle_keeps_manual_api_only(): void
    {
        $this->app['config']->set('faultnest.capture.exceptions', false);
        FaultNest::init();
        self::assertFalse($this->app->make(\FaultNest\Laravel\FaultNestManager::class)->isExceptionReportingReady());
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('auto'));
        self::assertSame([], $this->sink->events);
        self::assertSame(CaptureStatus::Captured, FaultNest::captureMessage('manual')->status);
    }

    public function test_config_is_cache_serializable(): void
    {
        // config:cache requires var_export-able data: scalars, null and arrays only.
        $config = $this->app['config']->get('faultnest');
        array_walk_recursive($config, function (mixed $value): void {
            self::assertTrue($value === null || is_scalar($value));
        });
    }

    public function test_own_delivery_exception_is_not_recaptured(): void
    {
        FaultNest::init();
        $this->app->make(ExceptionHandler::class)->report(new NotificationDeliveryException('transport down'));
        self::assertSame([], $this->sink->events);
    }

    // ---- Regression tests for defects found in review ----

    public function test_init_override_replaces_list_values_instead_of_merging_by_index(): void
    {
        $this->app['config']->set('faultnest.filters', [DropMarkedFilter::class, ThrowingFilter::class]);
        FaultNest::init(['filters' => [DropMarkedFilter::class]]);
        self::assertSame(CaptureStatus::Captured, FaultNest::captureMessage('kept')->status,
            'A list override must replace the configured list, not keep trailing entries.');
    }

    public function test_worker_timeout_of_own_alert_job_is_not_recaptured(): void
    {
        FaultNest::init();
        $job = $this->createStub(JobContract::class);
        $job->method('resolveName')->willReturn(SendAlertJob::class);

        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(TimeoutExceededException::forJob($job));
        $handler->report(MaxAttemptsExceededException::forJob($job));
        self::assertSame([], $this->sink->events, 'A hung transport must not feed alerts back into monitoring.');

        $other = $this->createStub(JobContract::class);
        $other->method('resolveName')->willReturn('App\\Jobs\\Import');
        $handler->report(TimeoutExceededException::forJob($other));
        self::assertCount(1, $this->sink->events, 'Host job timeouts stay monitored.');
    }

    public function test_decorated_exception_handler_such_as_collision_still_reports_once(): void
    {
        $original = $this->app->make(ExceptionHandler::class);
        $decorated = new class($original) implements ExceptionHandler {
            public int $reports = 0;
            public function __construct(private ExceptionHandler $inner) {}
            public function report(\Throwable $e): void { $this->reports++; $this->inner->report($e); }
            public function shouldReport(\Throwable $e): bool { return !$e instanceof \LogicException; }
            public function render($request, \Throwable $e) { return $this->inner->render($request, $e); }
            public function renderForConsole($output, \Throwable $e): void { $this->inner->renderForConsole($output, $e); }
        };
        $this->app->instance(ExceptionHandler::class, $decorated);
        FaultNest::init();
        FaultNest::init();

        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(new RuntimeException('console failure behind a decorator'));
        self::assertCount(1, $this->sink->events, 'Reports behind a decorated handler were silently lost.');
        self::assertSame(1, $decorated->reports, 'The host handler must still run.');

        $handler->report(new \LogicException('host says do not report'));
        self::assertCount(1, $this->sink->events, 'The host reporting decision stays authoritative.');
        self::assertFalse($handler->shouldReport(new \LogicException('x')));
    }

    public function test_invalid_scope_data_does_not_lose_the_exception(): void
    {
        FaultNest::init();
        FaultNest::setContext('model', new \stdClass());
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('real failure'));

        self::assertCount(1, $this->sink->events, 'An invalid scope value silently discarded the real error.');
        self::assertSame('real failure', $this->sink->events[0]->message);
        self::assertSame(['_faultnest' => 'scope_dropped'], $this->sink->events[0]->context);
    }

    public function test_oversized_breadcrumbs_do_not_disable_reporting_for_the_rest_of_the_scope(): void
    {
        FaultNest::init();
        for ($i = 0; $i < 400; $i++) {
            FaultNest::addBreadcrumb(new Breadcrumb('step '.$i, ['payload' => str_repeat('x', 100)]));
        }
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('after breadcrumbs'));
        self::assertCount(1, $this->sink->events);
        self::assertLessThanOrEqual(CaptureScope::MAX_BREADCRUMBS, count($this->app->make(CaptureScope::class)->breadcrumbs()));
    }

    public function test_explicit_invalid_call_context_still_fails_closed_without_storing_it(): void
    {
        self::assertSame(CaptureStatus::Failed, FaultNest::captureMessage('x', context: ['bad' => new \stdClass()])->status);
        self::assertSame([], $this->sink->events);
    }

    public function test_anonymous_exception_class_does_not_leak_absolute_path_or_nul(): void
    {
        $exception = new class('anon') extends RuntimeException {
            public function throwIt(): never { throw $this; }
        };
        try {
            $exception->throwIt();
        } catch (RuntimeException $caught) {
            $event = FaultNest::captureException($caught)->event;
        }

        self::assertStringNotContainsString("\0", (string) $event->exceptionType);
        self::assertStringNotContainsString(__DIR__, (string) $event->exceptionType);
        self::assertStringNotContainsString(__DIR__, (string) json_encode($event->frames));
        self::assertStringNotContainsString('\\u0000', (string) json_encode($event->frames));
    }

    protected function useDatabaseQueue(): void
    {
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $this->app['config']->set('queue.failed.driver', 'null');
        Schema::create('jobs', function ($table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}

class RecordingSink implements EventSinkInterface
{
    /** @var list<ErrorEvent> */
    public array $events = [];

    public function record(ErrorEvent $event): void
    {
        $this->events[] = $event;
    }
}

class ScopedFailingJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public string $name) {}

    public function handle(): void
    {
        FaultNest::setContext('job', $this->name);
        throw new RuntimeException('job failure');
    }
}

class DropMarkedFilter implements EventFilterInterface
{
    public function shouldCapture(ErrorEvent $event): bool
    {
        return !str_contains($event->message, 'drop');
    }
}

class ThrowingFilter implements EventFilterInterface
{
    public function shouldCapture(ErrorEvent $event): bool
    {
        throw new RuntimeException('filter failure');
    }
}

class MaskingTransformer implements EventTransformerInterface
{
    public function transform(ErrorEvent $event): ?ErrorEvent
    {
        return $event->withMessage('masked: '.$event->message);
    }
}

class ThrowingTransformer implements EventTransformerInterface
{
    public function transform(ErrorEvent $event): ?ErrorEvent
    {
        throw new RuntimeException('transformer failure');
    }
}

class FixedClassifier implements CategoryClassifierInterface
{
    public function classify(ErrorEvent $event): string
    {
        return 'fixed-category';
    }
}

class FixedFingerprint implements FingerprintStrategyInterface
{
    public function version(): int
    {
        return 9;
    }

    public function fingerprint(ErrorEvent $event): string
    {
        return 'fixed-fingerprint';
    }
}
