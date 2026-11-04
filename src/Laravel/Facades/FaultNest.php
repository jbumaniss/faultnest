<?php

namespace FaultNest\Laravel\Facades;

use FaultNest\Laravel\FaultNestManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void init(array<string, mixed> $overrides = [])
 * @method static \FaultNest\Domain\CaptureResult captureException(\Throwable $exception, array<string, mixed> $context = [], ?\FaultNest\Domain\ErrorLevel $level = null)
 * @method static \FaultNest\Domain\CaptureResult captureMessage(string $message, \FaultNest\Domain\ErrorLevel $level = \FaultNest\Domain\ErrorLevel::Error, array<string, mixed> $context = [])
 * @method static void setContext(string $key, mixed $value)
 * @method static void setTag(string $key, string $value)
 * @method static void addBreadcrumb(\FaultNest\Domain\Breadcrumb $breadcrumb)
 * @method static void clearScope()
 */
class FaultNest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FaultNestManager::class;
    }
}
