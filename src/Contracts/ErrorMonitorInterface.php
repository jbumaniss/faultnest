<?php

namespace FaultNest\Contracts;

use FaultNest\Browser\BrowserEventData;
use FaultNest\Domain\{Breadcrumb, CaptureResult, ErrorLevel};
use Throwable;

/** Application-facing capture and explicit context API. */
interface ErrorMonitorInterface
{
    /** @param array<string, mixed> $context */
    public function captureException(Throwable $exception, array $context = [], ?ErrorLevel $level = null): CaptureResult;

    /** @param array<string, mixed> $context */
    public function captureMessage(string $message, ErrorLevel $level = ErrorLevel::Error, array $context = []): CaptureResult;

    public function captureBrowserEvent(BrowserEventData $event): CaptureResult;

    public function setContext(string $key, mixed $value): void;
    public function setTag(string $key, string $value): void;
    public function addBreadcrumb(Breadcrumb $breadcrumb): void;
    public function clearScope(): void;
}
