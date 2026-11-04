<?php

namespace FaultNest\Laravel;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Observes reports for hosts whose bound handler is not Laravel's own Handler, such as
 * Collision's console adapter in every default skeleton with dev dependencies installed.
 * Delegates everything; the host's reporting and rendering decisions stay authoritative.
 */
class ReportingExceptionHandler implements ExceptionHandler
{
    /** @param Closure(Throwable):void $observer */
    public function __construct(
        protected ExceptionHandler $handler,
        protected Closure $observer,
    ) {}

    public function report(Throwable $e): void
    {
        if ($this->handler->shouldReport($e)) {
            ($this->observer)($e);
        }
        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->handler->{$method}(...$arguments);
    }
}
