<?php

namespace FaultNest\Domain;

/** Ordered diagnostic severity levels. */
enum ErrorLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public function priority(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
            self::Critical => 4,
        };
    }

    public function meets(self $minimum): bool
    {
        return $this->priority() >= $minimum->priority();
    }
}
