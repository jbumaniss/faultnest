<?php

namespace FaultNest\Browser;

use FaultNest\Contracts\ErrorMonitorInterface;
use FaultNest\Domain\CaptureResult;
use Throwable;

/** Called only by a host-owned controller after its own auth, CSRF and rate limits. */
class BrowserEventReceiver
{
    public function __construct(
        protected ErrorMonitorInterface $monitor,
        protected BrowserEventParser $parser,
        protected bool $enabled = false,
    ) {}

    /** @param array<string, mixed> $payload Decoded JSON from the installing application. */
    public function accept(array $payload): CaptureResult
    {
        if (!$this->enabled) {
            return CaptureResult::ignored();
        }
        try {
            return $this->monitor->captureBrowserEvent($this->parser->parse($payload));
        } catch (Throwable) {
            // Untrusted frontend input and monitoring failures must not affect host requests.
            return CaptureResult::failed();
        }
    }
}
