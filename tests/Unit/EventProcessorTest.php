<?php

namespace FaultNest\Tests\Unit;

use DateTimeImmutable;
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Grouping\{DefaultCategoryClassifier, DefaultFingerprintStrategy};
use FaultNest\Processing\{EventProcessor, MonitorOptions, SafePayload};
use FaultNest\Storage\NullEventSink;
use LogicException;
use PHPUnit\Framework\TestCase;

class EventProcessorTest extends TestCase
{
    /** Exception types can come from untrusted input, so the ignore list must not autoload them. */
    public function test_ignore_list_never_autoloads_an_event_type_name(): void
    {
        $seen = [];
        $autoload = function (string $class) use (&$seen): void { $seen[] = $class; };
        spl_autoload_register($autoload);
        try {
            $options = MonitorOptions::fromArray([
                'enabled' => true, 'project' => 'p', 'environment' => 'e', 'ignore_exceptions' => [LogicException::class],
                'classification' => ['driver' => 'c'], 'grouping' => ['driver' => 'g'],
            ]);
            $processor = new EventProcessor($options, new SafePayload($options), new DefaultCategoryClassifier(),
                new DefaultFingerprintStrategy(), new NullEventSink(), [], []);
            $event = new ErrorEvent(str_repeat('a', 32), 'p', 'e', 'browser', ErrorLevel::Error,
                'Attacker\\Chosen\\ClassName', 'boom', new DateTimeImmutable(), null, null);
            self::assertSame(CaptureStatus::Captured, $processor->process($event)->status);
        } finally {
            spl_autoload_unregister($autoload);
        }
        self::assertNotContains('Attacker\\Chosen\\ClassName', $seen);
    }
}
