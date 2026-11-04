<?php

namespace FaultNest\Laravel;

use FaultNest\Capture\{CaptureScope, ErrorMonitor};
use FaultNest\Contracts\{CategoryClassifierInterface, ErrorMonitorInterface, EventFilterInterface, EventReaderInterface, EventSinkInterface, EventTransformerInterface, FingerprintStrategyInterface, GroupReaderInterface};
use FaultNest\Domain\{Breadcrumb, CaptureResult, ErrorLevel};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions, SafePayload};
use FaultNest\Notifications\{ChannelSettings, NotificationDeliveryException, NotificationEventSink};
use FaultNest\Storage\{CacheGroupStore, FanoutEventSink, NullEventSink};
use FaultNest\Storage\Database\{DatabaseEventReader, DatabaseEventStore, DatabaseGroupReader, DatabaseHistoryPruner, HistorySettings};
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use InvalidArgumentException;
use Throwable;

/** Idempotent Laravel bootstrapper; never registers HTTP routes or outgoing transports. */
class FaultNestManager implements ErrorMonitorInterface
{
    protected ?ErrorMonitor $monitor = null;
    protected bool $initialized = false;
    protected bool $initializationFailed = false;

    public function __construct(protected Application $app) {}

    /** @param array<string, mixed> $overrides */
    public function init(array $overrides = []): void
    {
        if ($this->initialized || $this->initializationFailed) {
            return;
        }

        try {
            $config = $this->app->make('config')->get('faultnest', []);
            $config = array_replace_recursive($config, $overrides);
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
            $this->monitor = new ErrorMonitor($options, $factory, $processor, fn (): CaptureScope => $this->app->make(CaptureScope::class));
            $this->initialized = true;

            if ($options->enabled && $options->captureExceptions) {
                $handler = $this->app->make(ExceptionHandler::class);
                if ($handler instanceof Handler) {
                    $handler->reportable(function (Throwable $exception): void {
                        if ($exception instanceof NotificationDeliveryException) {
                            return;
                        }
                        $this->monitor?->report($exception);
                    });
                }
            }
        } catch (Throwable) {
            // Monitoring must never prevent the host from booting.
            $this->monitor = null;
            $this->initializationFailed = true;
        }
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
}
