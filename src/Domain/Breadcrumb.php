<?php

namespace FaultNest\Domain;

/** A breadcrumb is recorded only when host code adds it explicitly. */
readonly class Breadcrumb
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $message,
        public array $data = [],
    ) {}
}
