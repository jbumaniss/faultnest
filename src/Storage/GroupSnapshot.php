<?php

namespace FaultNest\Storage;

use DateTimeImmutable;
use FaultNest\Domain\ErrorLevel;

/** Small host-cache group summary; deliberately contains no caller context. */
readonly class GroupSnapshot
{
    public function __construct(
        public string $id,
        public string $project,
        public string $environment,
        public string $fingerprint,
        public int $fingerprintVersion,
        public string $category,
        public string $title,
        public ErrorLevel $maxLevel,
        public int $occurrences,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
    ) {}

    /** @return array<string, int|string> Scalars only: hosts may forbid unserializing objects from cache. */
    public function toCache(): array
    {
        return [
            'id' => $this->id, 'project' => $this->project, 'environment' => $this->environment,
            'fingerprint' => $this->fingerprint, 'fingerprint_version' => $this->fingerprintVersion,
            'category' => $this->category, 'title' => $this->title, 'max_level' => $this->maxLevel->value,
            'occurrences' => $this->occurrences,
            'first_seen_at' => $this->firstSeenAt->format('Y-m-d\\TH:i:s.uP'),
            'last_seen_at' => $this->lastSeenAt->format('Y-m-d\\TH:i:s.uP'),
        ];
    }

    /** @param array<string, int|string> $data */
    public static function fromCache(array $data): self
    {
        return new self(
            (string) $data['id'], (string) $data['project'], (string) $data['environment'],
            (string) $data['fingerprint'], (int) $data['fingerprint_version'], (string) $data['category'],
            (string) $data['title'], ErrorLevel::from((string) $data['max_level']), (int) $data['occurrences'],
            new DateTimeImmutable((string) $data['first_seen_at']), new DateTimeImmutable((string) $data['last_seen_at']),
        );
    }
}
