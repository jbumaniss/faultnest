<?php

namespace FaultNest\Domain;

/** Captured means processed locally, not persisted or delivered. */
readonly class CaptureResult
{
    public function __construct(
        public CaptureStatus $status,
        public ?ErrorEvent $event = null,
    ) {}

    public static function captured(ErrorEvent $event): self
    {
        return new self(CaptureStatus::Captured, $event);
    }

    public static function ignored(): self
    {
        return new self(CaptureStatus::Ignored);
    }

    public static function failed(): self
    {
        return new self(CaptureStatus::Failed);
    }
}
