<?php

namespace FaultNest\Laravel;

use FaultNest\Capture\CaptureScope;
use FaultNest\Contracts\{ErrorMonitorInterface, EventSinkInterface};
use FaultNest\Storage\NullEventSink;
use Illuminate\Support\ServiceProvider;

/** Package discovery binds services only; opt-in initialization remains explicit. */
class FaultNestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/faultnest.php', 'faultnest');
        $this->app->scoped(CaptureScope::class, fn (): CaptureScope => new CaptureScope());
        $this->app->bindIf(EventSinkInterface::class, NullEventSink::class);
        $this->app->singleton(FaultNestManager::class, fn ($app): FaultNestManager => new FaultNestManager($app));
        $this->app->alias(FaultNestManager::class, ErrorMonitorInterface::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/faultnest.php' => config_path('faultnest.php'),
        ], 'faultnest-config');
    }
}
