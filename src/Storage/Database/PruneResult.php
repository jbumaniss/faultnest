<?php

namespace FaultNest\Storage\Database;

/** Counts records actually deleted from the host's configured database. */
readonly class PruneResult
{
    public function __construct(
        public int $events,
        public int $groups,
    ) {}
}
