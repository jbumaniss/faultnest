<?php

namespace FaultNest\Storage\Database;

/**
 * Stable, bounded page of host-owned records, never an HTTP response.
 *
 * @template T
 */
readonly class Page
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
