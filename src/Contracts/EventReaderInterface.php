<?php

namespace FaultNest\Contracts;

use FaultNest\Domain\ErrorEvent;
use FaultNest\Storage\Database\{EventQuery, Page};

/** Host-side, read-only event samples; optional data is returned as it was stored. */
interface EventReaderInterface
{
    /** @return Page<ErrorEvent> */
    public function paginate(EventQuery $query): Page;

    public function find(string $eventId, string $project, string $environment): ?ErrorEvent;
}
