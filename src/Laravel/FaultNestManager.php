<?php

namespace FaultNest\Laravel;

use FaultNest\Capture\{CaptureScope, ErrorMonitor};
use FaultNest\Browser\{BrowserEventData, BrowserEventParser, BrowserEventReceiver};
use FaultNest\Contracts\{CategoryClassifierInterface, ErrorMonitorInterface, EventFilterInterface, EventReaderInterface, EventSinkInterface, EventTransformerInterface, FingerprintStrategyInterface, GroupReaderInterface};
use FaultNest\Domain\{Breadcrumb, CaptureResult, ErrorLevel};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions, SafePayload};
use FaultNest\Notifications\{ChannelSettings, NotificationDeliveryException, NotificationEventSink};
use FaultNest\Notifications\Jobs\SendAlertJob;
use FaultNest\Storage\{CacheGroupStore, FanoutEventSink, NullEventSink};
use FaultNest\Storage\Database\{DatabaseEventReader, DatabaseEventStore, DatabaseGroupReader, DatabaseHistoryPruner, HistorySettings};
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\MaxAttemptsExceededException;
use InvalidArgumentException;
use Throwable;

/** Idempotent Laravel bootstrapper; never registers HTTP routes or outgoing transports. */
class FaultNestManager implements ErrorMonitorInterface
{
    protected ?ErrorMonitor $monitor = null;
    protected bool $initialized = false;
    protected bool $initializationFailed = false;
    protected ?BrowserEventReceiver $browserReceiver = null;

    protected bool $processingJob = false;

    public function __construct(protected Application $app) {}

    /** @param array<string, mixed> $overrides */
    public function init(array $overrides = []): void
    {
        if ($this->initialized || $this->initializationFailed) {
            return;
        }

        try {
            $config = $this->app->make('config')->get('faultnest', []);
            $config = $this->mergeOverrides($config, $overrides);
            $options = MonitorOptions::fromArray($config);
            $payload = new SafePayload($options);
            $classifier = $this->app->make($options->classifier);
            $fingerprint = $this->app->make($options->fingerprintStrategy);
            $sink = $this->app->make(EventSinkInterface::class);
            $sinks = $sink instanceof NullEventSink ? [] : [$sink];
            if ($options->enabled) {
                $storage = $config['storage'] ?? [];
                if (!is_array($storage) || !is_bool($storage['enabled'] ?? false)) {
                    throw new InvalidArgumentException('Invalid FaultNest storage settings.');
                }
                if ($storage['enabled'] ?? false) {
                    $ttl = $storage['ttl_seconds'] ?? 86400;
                    $cacheStore = $storage['cache_store'] ?? null;
                    if (!is_int($ttl) || ($cacheStore !== null && (!is_string($cacheStore) || $cacheStore === ''))) {
                        throw new InvalidArgumentException('Invalid FaultNest cache group options.');
                    }
                    $groupStore = new CacheGroupStore($this->app->make('cache')->store($cacheStore), $ttl);
                    $this->app->instance(CacheGroupStore::class, $groupStore);
                    $sinks[] = $groupStore;
                }
                $historyConfig = $storage['database'] ?? [];
                if (!is_array($historyConfig)) {
                    throw new InvalidArgumentException('Invalid FaultNest database section.');
                }
                $history = HistorySettings::fromArray($historyConfig);
                if ($history->enabled) {
                    $connection = $this->app->make('db')->connection($history->connection);
                    $sinks[] = new DatabaseEventStore($connection, $history);
                    if (!$this->app->bound(GroupReaderInterface::class)) {
                        $this->app->instance(GroupReaderInterface::class, new DatabaseGroupReader($connection));
                    }
                    if (!$this->app->bound(EventReaderInterface::class)) {
                        $this->app->instance(EventReaderInterface::class, new DatabaseEventReader($connection));
                    }
                    $this->app->instance(DatabaseHistoryPruner::class, new DatabaseHistoryPruner($connection, $history));
                }
                $notifications = $config['notifications'] ?? [];
                if (!is_array($notifications) || !is_bool($notifications['enabled'] ?? false)) {
                    throw new InvalidArgumentException('Invalid FaultNest notification settings.');
                }
                if ($notifications['enabled'] ?? false) {
                    $definitions = $notifications['channels'] ?? [];
                    if (!is_array($definitions)) {
                        throw new InvalidArgumentException('Invalid FaultNest notification channel map.');
                    }
                    $channels = [];
                    foreach ($definitions as $name => $definition) {
                        if (!is_string($name) || !is_array($definition)) {
                            throw new InvalidArgumentException('Invalid FaultNest channel entry.');
                        }
                        $channels[] = ChannelSettings::fromArray($name, $definition);
                    }
                    $sinks[] = new NotificationEventSink(
                        $this->app, $this->app->make('cache'),
                        $this->app->make(Dispatcher::class), $channels,
                    );
                }
            }
            $sink = count($sinks) === 0 ? new NullEventSink() : new FanoutEventSink($sinks);
            $filters = [];
            $transformers = [];

            if (!$classifier instanceof CategoryClassifierInterface || !$fingerprint instanceof FingerprintStrategyInterface) {
                throw new InvalidArgumentException('Invalid FaultNest classifier or fingerprint strategy.');
            }

            foreach ($options->filters as $class) {
                $filter = $this->app->make($class);
                if (!$filter instanceof EventFilterInterface) {
                    throw new InvalidArgumentException('Invalid FaultNest filter.');
                }
                $filters[] = $filter;
            }

            foreach ($options->transformers as $class) {
                $transformer = $this->app->make($class);
                if (!$transformer instanceof EventTransformerInterface) {
                    throw new InvalidArgumentException('Invalid FaultNest transformer.');
                }
                $transformers[] = $transformer;
            }

            $factory = new EventFactory($options, $payload, $this->app->basePath());
            $processor = new EventProcessor($options, $payload, $classifier, $fingerprint, $sink, $filters, $transformers);
            $browserConfig = $config['browser'] ?? [];
            if (!is_array($browserConfig) || !is_bool($browserConfig['enabled'] ?? false)) {
                throw new InvalidArgumentException('Invalid FaultNest browser integration settings.');
            }
            $browserLevel = $browserConfig['max_level'] ?? 'error';
            $browserSize = $browserConfig['max_payload_bytes'] ?? 16384;
            if (!is_string($browserLevel) || ErrorLevel::tryFrom($browserLevel) === null || !is_int($browserSize)) {
                throw new InvalidArgumentException('Invalid FaultNest browser limits.');
            }
            $parser = new BrowserEventParser($browserSize, ErrorLevel::from($browserLevel));
            $this->browserReceiver = new BrowserEventReceiver($this, $parser,
                $options->enabled && $browserConfig['enabled']);
            $this->monitor = new ErrorMonitor($options, $factory, $processor, fn (): CaptureScope => $this->app->make(CaptureScope::class),
                fn (): string => $this->lifecycleSource());
            $this->trackQueueJobs();
            $this->initialized = true;

            if ($options->enabled && $options->captureExceptions) {
                $observer = function (Throwable $exception): void {
                    if (!$this->isOwnDeliveryFailure($exception)) {
                        $this->monitor?->report($exception);
                    }
                };
                $handler = $this->app->make(ExceptionHandler::class);
                if ($handler instanceof Handler) {
                    $handler->reportable($observer);
                } else {
                    $this->app->extend(ExceptionHandler::class, fn (ExceptionHandler $inner): ExceptionHandler
                        => new ReportingExceptionHandler($inner, $observer));
                }
            }
        } catch (Throwable) {
            // Monitoring must never prevent the host from booting.
            $this->monitor = null;
            $this->initializationFailed = true;
        }
    }

    /**
     * Lists are replaced as a whole, so an override cannot keep trailing configured entries such as recipients.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    protected function mergeOverrides(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? $this->mergeOverrides($base[$key], $value)
                : $value;
        }
        return $base;
    }

    /** The worker reports timeouts itself; re-alerting about a hung alert job would loop. */
    protected function isOwnDeliveryFailure(Throwable $exception): bool
    {
        return $exception instanceof NotificationDeliveryException
            || ($exception instanceof MaxAttemptsExceededException && $exception->job?->resolveName() === SendAlertJob::class);
    }

    public function captureException(Throwable $exception, array $context = [], ?ErrorLevel $level = null): CaptureResult
    {
        $this->init();
        return $this->monitor?->captureException($exception, $context, $level) ?? CaptureResult::failed();
    }

    public function captureMessage(string $message, ErrorLevel $level = ErrorLevel::Error, array $context = []): CaptureResult
    {
        $this->init();
        return $this->monitor?->captureMessage($message, $level, $context) ?? CaptureResult::failed();
    }

    /** Local receiver for the host's controller; no routes are registered. */
    public function browserReceiver(): BrowserEventReceiver
    {
        $this->init();
        return $this->browserReceiver ?? new BrowserEventReceiver($this, new BrowserEventParser(), false);
    }

    public function captureBrowserEvent(BrowserEventData $event): CaptureResult
    {
        $this->init();
        return $this->monitor?->captureBrowserEvent($event) ?? CaptureResult::failed();
    }

    public function setContext(string $key, mixed $value): void
    {
        try {
            $this->app->make(CaptureScope::class)->setContext($key, $value);
        } catch (Throwable) {
            // Explicit context is best effort; never fail the host application.
        }
    }

    public function setTag(string $key, string $value): void
    {
        try {
            $this->app->make(CaptureScope::class)->setTag($key, $value);
        } catch (Throwable) {
            // Explicit context is best effort; never fail the host application.
        }
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        try {
            $this->app->make(CaptureScope::class)->addBreadcrumb($breadcrumb);
        } catch (Throwable) {
            // Explicit context is best effort; never fail the host application.
        }
    }

    public function clearScope(): void
    {
        try {
            $this->app->make(CaptureScope::class)->clear();
        } catch (Throwable) {
            // Explicit context is best effort; never fail the host application.
        }
    }

    /** Names only the lifecycle kind; nothing is read from the request, command or job. */
    protected function lifecycleSource(): string
    {
        if (!$this->app->runningInConsole()) {
            return 'http';
        }
        return $this->processingJob ? 'queue' : 'console';
    }

    protected function trackQueueJobs(): void
    {
        $events = $this->app->make('events');
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, function (): void { $this->processingJob = true; });
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, function (): void { $this->processingJob = false; });
        // The worker reports a failed job after it finishes, so failures are only cleared by the next loop.
        $events->listen(\Illuminate\Queue\Events\Looping::class, function (): void { $this->processingJob = false; });
    }
}
