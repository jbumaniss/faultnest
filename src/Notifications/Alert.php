<?php

namespace FaultNest\Notifications;

use FaultNest\Domain\ErrorEvent;

/** Sanitized event selected for a configured notification channel. */
readonly class Alert
{
    /** @param list<string> $includeTags Explicitly selected metadata keys, not full context. */
    public function __construct(
        public ErrorEvent $event,
        public bool $includeContext = false,
        public array $includeTags = [],
    ) {}
}
