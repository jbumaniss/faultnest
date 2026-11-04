<?php

namespace FaultNest\Laravel;

use FaultNest\Capture\{CaptureScope, ErrorMonitor};
use FaultNest\Contracts\{CategoryClassifierInterface, ErrorMonitorInterface, EventFilterInterface, EventSinkInterface, EventTransformerInterface, FingerprintStrategyInterface};
use FaultNest\Domain\{Breadcrumb, CaptureResult, ErrorLevel};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions, SafePayload};
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
            $options = MonitorOptions::fromArray(array_replace_recursive($config, $overrides));
            $payload = new SafePayload($options);
            $classifier = $this->app->make($options->classifier);
            $fingerprint = $this->app->make($options->fingerprintStrategy);
            $sink = $this->app->make(EventSinkInterface::class);
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
