<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;

interface EventTransformerInterface
{
    /** Returning null discards the event before grouping and any future sinks. */
    public function transform(ErrorEvent $event): ?ErrorEvent;
}
