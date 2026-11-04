<?php

namespace FaultNest\Storage;

use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\ErrorEvent;

/** Explicit no-op until a host-owned storage adapter is configured. */
class NullEventSink implements EventSinkInterface
{
    public function record(ErrorEvent $event): void {}
}
