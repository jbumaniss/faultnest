<?php

namespace FaultNest\Storage\Database;

use FaultNest\Domain\ErrorLevel;
use InvalidArgumentException;

/** Explicit host-project scope and bounded group-list filters. */
readonly class GroupQuery
{
    public function __construct(
        public string $project,
        public string $environment,
        public ?string $category = null,
        public ?ErrorLevel $level = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
        if (trim($project) === '' || trim($environment) === ''
            || ($category !== null && (trim($category) === '' || strlen($category) > 80))
            || $page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid FaultNest group query.');
        }
    }
}
