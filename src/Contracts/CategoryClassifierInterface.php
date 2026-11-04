<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;

interface CategoryClassifierInterface
{
    public function classify(ErrorEvent $event): string;
}
