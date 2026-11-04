<?php

namespace FaultNest\Storage\Database;

use InvalidArgumentException;

/** Validated opt-in history settings for the installing application's database. */
readonly class HistorySettings
{
    public function __construct(
        public bool $enabled,
        public ?string $connection,
        public int $retentionDays,
        public int $maxSamplesPerGroupPerDay,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $enabled = $config['enabled'] ?? false;
        $connection = $config['connection'] ?? null;
        $retention = $config['retention_days'] ?? 30;
        $samples = $config['max_samples_per_group_per_day'] ?? 3;

        if (!is_bool($enabled)
            || ($connection !== null && (!is_string($connection) || trim($connection) === ''))
            || !is_int($retention) || $retention < 1 || $retention > 3650
            || !is_int($samples) || $samples < 0 || $samples > 100) {
            throw new InvalidArgumentException('Invalid FaultNest database history settings.');
        }

        return new self($enabled, $connection, $retention, $samples);
    }
}
