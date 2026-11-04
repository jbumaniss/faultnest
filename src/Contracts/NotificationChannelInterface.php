<?php

namespace FaultNest\Contracts;

use FaultNest\Notifications\{Alert, DeliveryTarget};

/** Host-enabled destination. Custom channels do not require changes to the core. */
interface NotificationChannelInterface
{
    public function send(Alert $alert, DeliveryTarget $target): void;
}
