<?php

namespace FaultNest\Storage;

use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\ErrorEvent;
use Throwable;

/** Storage and notifications are independently attempted; failure never escapes capture. */
class FanoutEventSink implements EventSinkInterface
{
    /** @param list<EventSinkInterface> $sinks */
    public function __construct(protected array $sinks) {}

    public function record(ErrorEvent $event): void
    {
        $succeeded = false;
        $failure = null;
        foreach ($this->sinks as $sink) {
            try {
                $sink->record($event);
                $succeeded = true;
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        }
        if (!$succeeded && $failure !== null) {
            throw $failure;
        }
    }
}
