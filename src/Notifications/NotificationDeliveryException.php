<?php

namespace FaultNest\Notifications;

use RuntimeException;

/** Marks FaultNest's own queue failures to prevent recursive auto-monitoring. */
class NotificationDeliveryException extends RuntimeException {}
