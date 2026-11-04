<?php

namespace FaultNest\Laravel\Facades;

use FaultNest\Laravel\FaultNestManager;
use Illuminate\Support\Facades\Facade;

/** @method static void init(array $overrides = []) */
class FaultNest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FaultNestManager::class;
    }
}
