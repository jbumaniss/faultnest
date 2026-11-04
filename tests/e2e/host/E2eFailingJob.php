<?php

namespace App\Jobs;

use FaultNest\Laravel\Facades\FaultNest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class E2eFailingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        FaultNest::setContext('job', 'explicit-job-context');
        throw new RuntimeException('queue e2e failure');
    }
}
