<?php

namespace FaultNest\Notifications;

/** Cache reservation identity carried through the host's queue. */
readonly class Reservation
{
    public function __construct(
        public string $key,
        public string $token,
        public int $cooldownSeconds,
    ) {}
}
