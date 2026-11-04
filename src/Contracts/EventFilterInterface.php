<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;

interface EventFilterInterface
{
    public function shouldCapture(ErrorEvent $event): bool;
}
