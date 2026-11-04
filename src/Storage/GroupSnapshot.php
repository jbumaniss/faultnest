<?php

namespace FaultNest\Storage;

use DateTimeImmutable;
use FaultNest\Domain\ErrorLevel;

/** Small host-cache group summary; deliberately contains no caller context. */
readonly class GroupSnapshot
{
    public function __construct(
        public string $id,
        public string $project,
        public string $environment,
        public string $fingerprint,
        public int $fingerprintVersion,
        public string $category,
        public string $title,
        public ErrorLevel $maxLevel,
        public int $occurrences,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
    ) {}
}
