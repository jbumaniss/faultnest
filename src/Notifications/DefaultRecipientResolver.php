<?php

namespace FaultNest\Notifications;

use FaultNest\Contracts\RecipientResolverInterface;
use FaultNest\Domain\ErrorEvent;

/** Default routing uses only the host's explicitly configured recipients. */
class DefaultRecipientResolver implements RecipientResolverInterface
{
    public function resolve(ErrorEvent $event, array $configured): array
    {
        return $configured;
    }
}
