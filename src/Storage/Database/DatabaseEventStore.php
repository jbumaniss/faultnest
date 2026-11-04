<?php

namespace FaultNest\Storage\Database;

use DateTimeZone;
use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\ErrorEvent;
use FaultNest\Storage\GroupIdentity;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Writes host-owned group counters and bounded diagnostic samples atomically. */
class DatabaseEventStore implements EventSinkInterface
{
    public function __construct(
        protected ConnectionInterface $database,
        protected HistorySettings $settings,
    ) {}

    public function record(ErrorEvent $event): void
    {
        $groupId = GroupIdentity::id($event);
        $occurredAt = $event->occurredAt->setTimezone(new DateTimeZone('UTC'));
        $occurred = $occurredAt->format('Y-m-d H:i:s.u');
        $now = gmdate('Y-m-d H:i:s');

        $this->database->transaction(function () use ($event, $groupId, $occurredAt, $occurred, $now): void {
            $this->database->table('faultnest_groups')->insertOrIgnore([
                'id' => $groupId,
                'project' => $event->project,
                'environment' => $event->environment,
                'fingerprint_version' => $event->fingerprintVersion,
                'fingerprint' => $event->fingerprint,
                'category' => $event->category,
                'title' => $event->message,
                'max_level' => $event->level->value,
                'occurrence_count' => 0,
                'first_seen_at' => $occurred,
                'last_seen_at' => $occurred,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Serialize concurrent writes for this group before counting sample quota.
            $group = $this->database->table('faultnest_groups')
                ->where('id', $groupId)->lockForUpdate()->first();
            if ($group === null) {
                throw new RuntimeException('FaultNest database group could not be locked.');
            }

            $maxLevel = \FaultNest\Domain\ErrorLevel::from($group->max_level);
            $this->database->table('faultnest_groups')->where('id', $groupId)->update([
                'max_level' => $event->level->priority() > $maxLevel->priority()
                    ? $event->level->value : $group->max_level,
                'occurrence_count' => (int) $group->occurrence_count + 1,
                'last_seen_at' => $occurred > $group->last_seen_at ? $occurred : $group->last_seen_at,
                'first_seen_at' => $occurred < $group->first_seen_at ? $occurred : $group->first_seen_at,
                'updated_at' => $now,
            ]);

            // Zero samples keeps group statistics without retaining event details.
            if ($this->settings->maxSamplesPerGroupPerDay === 0) {
                return;
            }

            $dayStart = $occurredAt->setTime(0, 0)->format('Y-m-d H:i:s.u');
            $dayEnd = $occurredAt->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s.u');
            $samples = $this->database->table('faultnest_event_samples')
                ->where('group_id', $groupId)
                ->where('occurred_at', '>=', $dayStart)
                ->where('occurred_at', '<', $dayEnd)
                ->count();
            if ($samples >= $this->settings->maxSamplesPerGroupPerDay) {
                return;
            }

            $this->database->table('faultnest_event_samples')->insert([
                'id' => $event->id,
                'group_id' => $groupId,
                'source' => $event->source,
                'level' => $event->level->value,
                'exception_type' => $event->exceptionType,
                'message' => $event->message,
                'file' => $event->file,
                'line' => $event->line,
                'frames_json' => json_encode($event->frames, JSON_THROW_ON_ERROR),
                'context_json' => json_encode($event->context, JSON_THROW_ON_ERROR),
                'tags_json' => json_encode($event->tags, JSON_THROW_ON_ERROR),
                'breadcrumbs_json' => json_encode($event->breadcrumbs, JSON_THROW_ON_ERROR),
                'occurred_at' => $occurred,
                'created_at' => $now,
            ]);
        }, 3);
    }
}
