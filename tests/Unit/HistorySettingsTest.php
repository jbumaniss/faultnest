<?php

namespace FaultNest\Tests\Unit;

use FaultNest\Storage\Database\{EventQuery, GroupQuery, HistorySettings};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HistorySettingsTest extends TestCase
{
    public function test_history_disabled_by_default(): void
    {
        $options = HistorySettings::fromArray([]);
        self::assertFalse($options->enabled);
        self::assertSame(30, $options->retentionDays);
        self::assertSame(3, $options->maxSamplesPerGroupPerDay);
    }

    public function test_invalid_limits_fail_configuration_validation(): void
    {
        foreach ([-1, 101, '3'] as $value) {
            try {
                HistorySettings::fromArray(['max_samples_per_group_per_day' => $value]);
                self::fail('Invalid sample quota accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1); // Expected configuration guard.
            }
        }
    }

    public function test_zero_samples_keeps_only_group_counters(): void
    {
        self::assertSame(0, HistorySettings::fromArray(['max_samples_per_group_per_day' => 0])->maxSamplesPerGroupPerDay);
    }

    public function test_reader_queries_require_explicit_scope_and_bounded_pages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupQuery('', 'production');
    }

    public function test_event_query_rejects_unbounded_page_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EventQuery('test', 'production', perPage: 200);
    }
}
