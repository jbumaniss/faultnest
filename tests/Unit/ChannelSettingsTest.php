<?php

namespace FaultNest\Tests\Unit;

use FaultNest\Domain\ErrorLevel;
use FaultNest\Notifications\ChannelSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ChannelSettingsTest extends TestCase
{
    public function test_per_severity_cooldowns_are_customizable(): void
    {
        $settings = ChannelSettings::fromArray('mail', [
            'driver' => 'App\\MailChannel', 'enabled' => true,
            'cooldown_seconds' => 1800, 'level_cooldowns' => ['critical' => 60],
        ]);
        self::assertSame(1800, $settings->cooldownFor(ErrorLevel::Error));
        self::assertSame(60, $settings->cooldownFor(ErrorLevel::Critical));
    }

    public function test_invalid_pending_lease_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChannelSettings::fromArray('mail', [
            'driver' => 'App\\MailChannel', 'enabled' => true, 'pending_ttl_seconds' => -1,
        ]);
    }
}
