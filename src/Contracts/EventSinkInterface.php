<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;

/** Optional local sink. No outgoing transport or persistence is installed by default. */
interface EventSinkInterface
{
    public function record(ErrorEvent $event): void;
}
