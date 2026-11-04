<?php

namespace FaultNest\Browser;

use FaultNest\Domain\ErrorLevel;

/** Validated diagnostics from an application-owned browser ingestion endpoint. */
readonly class BrowserEventData
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $tags
     * @param list<array{message: string, data: array<string, mixed>}> $breadcrumbs
     */
    public function __construct(
        public string $source,
        public ?string $exceptionType,
        public string $message,
        public ErrorLevel $level,
        public ?string $stack,
        public array $context,
        public array $tags,
        public array $breadcrumbs,
    ) {}
}
