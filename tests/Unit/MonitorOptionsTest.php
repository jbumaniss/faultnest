<?php

namespace FaultNest\Tests\Unit;

use FaultNest\Domain\ErrorLevel;
use FaultNest\Grouping\{DefaultCategoryClassifier, DefaultFingerprintStrategy};
use FaultNest\Processing\MonitorOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MonitorOptionsTest extends TestCase
{
    public function test_unsupported_level_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MonitorOptions::fromArray([
            'enabled' => true, 'project' => 'demo', 'environment' => 'test',
            'min_level' => 'emergency',
            'classification' => ['driver' => DefaultCategoryClassifier::class],
            'grouping' => ['driver' => DefaultFingerprintStrategy::class],
        ]);
    }

    public function test_level_comparisons_are_ordered(): void
    {
        self::assertTrue(ErrorLevel::Critical->meets(ErrorLevel::Warning));
        self::assertFalse(ErrorLevel::Info->meets(ErrorLevel::Warning));
    }
}
