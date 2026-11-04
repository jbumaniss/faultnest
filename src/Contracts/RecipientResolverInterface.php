<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;

/** Resolve configured or runtime-defined destinations for a specific event. */
interface RecipientResolverInterface
{
    /** @param list<string> $configured @return list<string> */
    public function resolve(ErrorEvent $event, array $configured): array;
}
