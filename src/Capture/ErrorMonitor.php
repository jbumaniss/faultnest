<?php

namespace FaultNest\Capture;

use Closure;
use FaultNest\Browser\BrowserEventData;
use FaultNest\Contracts\ErrorMonitorInterface;
use FaultNest\Domain\{Breadcrumb, CaptureResult, ErrorLevel};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions};
use Throwable;

/** Framework-independent capture API with host-provided explicit scope. */
class ErrorMonitor implements ErrorMonitorInterface
{
    /**
     * @param Closure():CaptureScope $scopeResolver
     * @param (Closure():string)|null $reportSource Names the host lifecycle for automatic reports.
     */
    public function __construct(
        protected MonitorOptions $options,
        protected EventFactory $factory,
        protected EventProcessor $processor,
        protected Closure $scopeResolver,
        protected ?Closure $reportSource = null,
    ) {}

    public function captureException(Throwable $exception, array $context = [], ?ErrorLevel $level = null): CaptureResult
    {
        return $this->captureThrowable($exception, $context, $level, 'manual');
    }

    /** @param array<string, mixed> $context */
    public function report(Throwable $exception, array $context = []): CaptureResult
    {
        $source = $this->reportSource === null ? 'laravel' : ($this->reportSource)();
        return $this->captureThrowable($exception, $context, null, $source);
    }

    public function captureMessage(string $message, ErrorLevel $level = ErrorLevel::Error, array $context = []): CaptureResult
    {
        if (!$this->options->enabled) {
            return CaptureResult::ignored();
        }

        $scope = null;
        $entered = false;
        try {
            $scope = ($this->scopeResolver)();
            $entered = $scope->enter();
            if (!$entered) {
                return CaptureResult::ignored();
            }
            return $this->processor->process($this->factory->message($message, $level, $scope, $context));
        } catch (Throwable) {
            return CaptureResult::failed();
        } finally {
            if ($entered) {
                $scope->leave();
            }
        }
    }

    public function captureBrowserEvent(BrowserEventData $event): CaptureResult
    {
        if (!$this->options->enabled) {
            return CaptureResult::ignored();
        }
        $scope = null;
        $entered = false;
        try {
            $scope = ($this->scopeResolver)();
            $entered = $scope->enter();
            if (!$entered) {
                return CaptureResult::ignored();
            }
            return $this->processor->process($this->factory->browser($event, $scope));
        } catch (Throwable) {
            return CaptureResult::failed();
        } finally {
            if ($entered) {
                $scope->leave();
            }
        }
    }

    public function setContext(string $key, mixed $value): void
    {
        ($this->scopeResolver)()->setContext($key, $value);
    }

    public function setTag(string $key, string $value): void
    {
        ($this->scopeResolver)()->setTag($key, $value);
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        ($this->scopeResolver)()->addBreadcrumb($breadcrumb);
    }

    public function clearScope(): void
    {
        ($this->scopeResolver)()->clear();
    }

    /** @param array<string, mixed> $context */
    protected function captureThrowable(Throwable $exception, array $context, ?ErrorLevel $level, string $source): CaptureResult
    {
        if (!$this->options->enabled) {
            return CaptureResult::ignored();
        }

        $scope = null;
        $entered = false;
        try {
            $scope = ($this->scopeResolver)();
            $entered = $scope->enter();
            if (!$entered) {
                return CaptureResult::ignored();
            }
            return $this->processor->process($this->factory->exception($exception, $scope, $context, $level, $source));
        } catch (Throwable) {
            return CaptureResult::failed();
        } finally {
            if ($entered) {
                $scope->leave();
            }
        }
    }
}
