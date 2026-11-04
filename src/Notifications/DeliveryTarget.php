<?php

namespace FaultNest\Notifications;

/** Explicitly configured or resolved channel destination. */
readonly class DeliveryTarget
{
    public function __construct(public string $value) {}
}
