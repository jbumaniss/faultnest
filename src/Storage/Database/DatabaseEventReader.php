<?php

namespace FaultNest\Storage\Database;

use DateTimeImmutable;
use DateTimeZone;
use FaultNest\Contracts\EventReaderInterface;
use FaultNest\Domain\{ErrorEvent, ErrorLevel};
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Typed, local, read-only access to retained samples and explicitly supplied context. */
class DatabaseEventReader implements EventReaderInterface
{
    public function __construct(protected ConnectionInterface $database) {}

    /** @return Page<ErrorEvent> */
    public function paginate(EventQuery $query): Page
    {
        $builder = $this->scoped($query->project, $query->environment);
        if ($query->groupId !== null) {
            $builder->where('e.group_id', $query->groupId);
        }
        if ($query->level !== null) {
            $builder->where('e.level', $query->level->value);
        }
        $total = (int) (clone $builder)->count();
        $records = $builder->select('e.*', 'g.project', 'g.environment', 'g.category', 'g.fingerprint', 'g.fingerprint_version')
            ->orderByDesc('e.occurred_at')->orderBy('e.id')
            ->offset(($query->page - 1) * $query->perPage)->limit($query->perPage)->get();
        $items = [];
        foreach ($records as $record) {
            $items[] = $this->hydrate($record);
        }
        return new Page($items, $total, $query->page, $query->perPage);
    }

    public function find(string $eventId, string $project, string $environment): ?ErrorEvent
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $eventId)) {
            return null;
        }
        $record = $this->scoped($project, $environment)
            ->select('e.*', 'g.project', 'g.environment', 'g.category', 'g.fingerprint', 'g.fingerprint_version')
            ->where('e.id', $eventId)->first();
        return $record === null ? null : $this->hydrate($record);
    }

    protected function scoped(string $project, string $environment): Builder
    {
        if (trim($project) === '' || trim($environment) === '') {
            throw new InvalidArgumentException('FaultNest reads require explicit project and environment.');
        }
        return $this->database->table('faultnest_event_samples as e')
            ->join('faultnest_groups as g', 'e.group_id', '=', 'g.id')
            ->where('g.project', $project)->where('g.environment', $environment);
    }

    protected function hydrate(stdClass $record): ErrorEvent
    {
        return new ErrorEvent(
            $record->id, $record->project, $record->environment, $record->source,
            ErrorLevel::from($record->level), $record->exception_type, $record->message,
            new DateTimeImmutable($record->occurred_at, new DateTimeZone('UTC')),
            $record->file, $record->line === null ? null : (int) $record->line,
            $this->decode($record->frames_json),
            $this->decode($record->context_json),
            $this->decode($record->tags_json),
            $this->decode($record->breadcrumbs_json),
            $record->category, $record->fingerprint, (int) $record->fingerprint_version,
        );
    }

    /** @return array<mixed> */
    protected function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('FaultNest persisted JSON must be an array.');
        }
        return $decoded;
    }
}
