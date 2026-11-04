<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;

interface FingerprintStrategyInterface
{
    public function fingerprint(ErrorEvent $event): string;
    public function version(): int;
}
