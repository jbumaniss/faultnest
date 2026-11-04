<?php

namespace FaultNest\Notifications;

use FaultNest\Domain\ErrorEvent;

/** Sanitized event selected for a configured notification channel. */
readonly class Alert
{
    public function __construct(public ErrorEvent $event, public bool $includeContext = false) {}
}
