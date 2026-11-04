<?php

use App\Jobs\E2eFailingJob;
use Illuminate\Support\Facades\Artisan;

Artisan::command('e2e:boom', fn () => throw new RuntimeException('console e2e failure'));
Artisan::command('e2e:dispatch', function (): void {
    E2eFailingJob::dispatch();
});
