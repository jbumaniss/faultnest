<?php

namespace FaultNest\Laravel;

use FaultNest\Capture\CaptureScope;
use FaultNest\Contracts\{ErrorMonitorInterface, EventSinkInterface};
use FaultNest\Storage\NullEventSink;
use FaultNest\Laravel\Commands\PruneHistoryCommand;
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

        // Register the package migration only after the host opts in.
        if (config('faultnest.enabled') && config('faultnest.storage.database.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
            if ($this->app->runningInConsole()) {
                $this->commands([PruneHistoryCommand::class]);
            }
        }
    }
}
