<?php

namespace FaultNest\Storage\Database;

use DateTimeImmutable;
use DateTimeZone;
use FaultNest\Contracts\GroupReaderInterface;
use FaultNest\Domain\ErrorLevel;
use FaultNest\Storage\GroupSnapshot;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use stdClass;

/** Typed, local, read-only group queries over the host's database. */
class DatabaseGroupReader implements GroupReaderInterface
{
    public function __construct(protected ConnectionInterface $database) {}

    /** @return Page<GroupSnapshot> */
    public function paginate(GroupQuery $query): Page
    {
        $builder = $this->scoped($query->project, $query->environment);
        if ($query->category !== null) {
            $builder->where('category', $query->category);
        }
        if ($query->level !== null) {
            $builder->where('max_level', $query->level->value);
        }

        $total = (int) (clone $builder)->count();
        $records = $builder->orderByDesc('last_seen_at')->orderBy('id')
            ->offset(($query->page - 1) * $query->perPage)->limit($query->perPage)->get();
        $items = [];
        foreach ($records as $record) {
            $items[] = $this->hydrate($record);
        }
        return new Page($items, $total, $query->page, $query->perPage);
    }

    public function find(string $groupId, string $project, string $environment): ?GroupSnapshot
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $groupId)) {
            return null;
        }
        $record = $this->scoped($project, $environment)->where('id', $groupId)->first();
        return $record === null ? null : $this->hydrate($record);
    }

    protected function scoped(string $project, string $environment): Builder
    {
        if (trim($project) === '' || trim($environment) === '') {
            throw new InvalidArgumentException('FaultNest reads require explicit project and environment.');
        }
        return $this->database->table('faultnest_groups')
            ->where('project', $project)->where('environment', $environment);
    }

    protected function hydrate(stdClass $record): GroupSnapshot
    {
        return new GroupSnapshot(
            $record->id, $record->project, $record->environment,
            $record->fingerprint, (int) $record->fingerprint_version,
            $record->category, $record->title, ErrorLevel::from($record->max_level),
            (int) $record->occurrence_count,
            new DateTimeImmutable($record->first_seen_at, new DateTimeZone('UTC')),
            new DateTimeImmutable($record->last_seen_at, new DateTimeZone('UTC')),
        );
    }
}
