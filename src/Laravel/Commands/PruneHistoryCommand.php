<?php

namespace FaultNest\Laravel\Commands;

use FaultNest\Storage\Database\DatabaseHistoryPruner;
use Illuminate\Console\Command;

/** Host-operated pruning command; registered only when database history is enabled. */
class PruneHistoryCommand extends Command
{
    protected $signature = 'faultnest:prune {--max-batches=10 : Maximum batches of 500 records per table}';
    protected $description = 'Prune expired FaultNest event samples and groups from this application database';

    public function handle(DatabaseHistoryPruner $pruner): int
    {
        $batches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT);
        if ($batches === false || $batches < 1 || $batches > 1000) {
            $this->error('max-batches must be between 1 and 1000.');
            return self::FAILURE;
        }

        $result = $pruner->prune($batches);
        $this->info("Pruned {$result->events} event samples and {$result->groups} groups.");
        return self::SUCCESS;
    }
}
