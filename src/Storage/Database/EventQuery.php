<?php

namespace FaultNest\Storage\Database;

use FaultNest\Domain\ErrorLevel;
use InvalidArgumentException;

/** Explicit host-project scope and bounded event-list filters. */
readonly class EventQuery
{
    public function __construct(
        public string $project,
        public string $environment,
        public ?string $groupId = null,
        public ?ErrorLevel $level = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
        if (trim($project) === '' || trim($environment) === ''
            || ($groupId !== null && !preg_match('/^[0-9a-f]{64}$/', $groupId))
            || $page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid FaultNest event query.');
        }
    }
}
