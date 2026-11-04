<?php

namespace FaultNest\Storage\Database;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** Explicit, bounded retention pruning. Never scheduled by FaultNest. */
class DatabaseHistoryPruner
{
    public function __construct(
        protected ConnectionInterface $database,
        protected HistorySettings $settings,
    ) {}

    public function prune(int $maxBatches = 10, int $batchSize = 500, ?DateTimeImmutable $now = null): PruneResult
    {
        if ($maxBatches < 1 || $maxBatches > 1000 || $batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Invalid FaultNest pruning limits.');
        }

        $cutoff = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('-'.$this->settings->retentionDays.' days')
            ->format('Y-m-d H:i:s.u');

        $events = 0;
        $groups = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = $this->database->table('faultnest_event_samples')
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('occurred_at')->limit($batchSize)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $events += $this->database->table('faultnest_event_samples')
                ->whereIn('id', $ids)->where('occurred_at', '<', $cutoff)->delete();
        }

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = $this->database->table('faultnest_groups')
                ->where('last_seen_at', '<', $cutoff)
                ->orderBy('last_seen_at')->limit($batchSize)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $groups += $this->database->table('faultnest_groups')
                ->whereIn('id', $ids)->where('last_seen_at', '<', $cutoff)->delete();
        }

        return new PruneResult($events, $groups);
    }
}
