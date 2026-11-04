<?php

namespace FaultNest\Grouping;

use FaultNest\Contracts\CategoryClassifierInterface;
use FaultNest\Domain\ErrorEvent;

/** Small, replaceable classification rather than a growing exception catalogue. */
class DefaultCategoryClassifier implements CategoryClassifierInterface
{
    public function classify(ErrorEvent $event): string
    {
        $type = $event->exceptionType ?? '';

        if (str_contains($type, 'QueryException') || str_contains($type, 'PDOException')) {
            return 'database';
        }

        if (str_contains($type, 'ConnectionException') || str_contains($type, 'TransportException')) {
            return 'network';
        }

        if (str_contains($type, 'AuthenticationException') || str_contains($type, 'AuthorizationException')) {
            return 'authentication';
        }

        return 'application';
    }
}
