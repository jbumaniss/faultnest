<?php

namespace FaultNest\Domain;

use DateTimeImmutable;

/** Immutable, minimized diagnostic event. Optional context comes only from the host. */
readonly class ErrorEvent
{
    /**
     * @param list<array{file:string,line:int,call:string}> $frames
     * @param array<string, mixed> $context
     * @param array<string, string> $tags
     * @param list<array{message:string,data:array<string,mixed>}> $breadcrumbs
     */
    public function __construct(
        public string $id,
        public string $project,
        public string $environment,
        public string $source,
        public ErrorLevel $level,
        public ?string $exceptionType,
        public string $message,
        public DateTimeImmutable $occurredAt,
        public ?string $file,
        public ?int $line,
        public array $frames = [],
        public array $context = [],
        public array $tags = [],
        public array $breadcrumbs = [],
        public string $category = 'application',
        public string $fingerprint = '',
        public int $fingerprintVersion = 1,
    ) {}

    public function withIdentity(string $category, string $fingerprint, int $version): self
    {
        return new self(
            $this->id, $this->project, $this->environment, $this->source,
            $this->level, $this->exceptionType, $this->message, $this->occurredAt,
            $this->file, $this->line, $this->frames, $this->context, $this->tags,
            $this->breadcrumbs, $category, $fingerprint, $version,
        );
    }

    public function withMessage(string $message): self
    {
        return new self(
            $this->id, $this->project, $this->environment, $this->source,
            $this->level, $this->exceptionType, $message, $this->occurredAt,
            $this->file, $this->line, $this->frames, $this->context, $this->tags,
            $this->breadcrumbs, $this->category, $this->fingerprint, $this->fingerprintVersion,
        );
    }

    public function withLevel(ErrorLevel $level): self
    {
        return new self(
            $this->id, $this->project, $this->environment, $this->source,
            $level, $this->exceptionType, $this->message, $this->occurredAt,
            $this->file, $this->line, $this->frames, $this->context, $this->tags,
            $this->breadcrumbs, $this->category, $this->fingerprint, $this->fingerprintVersion,
        );
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        return new self(
            $this->id, $this->project, $this->environment, $this->source,
            $this->level, $this->exceptionType, $this->message, $this->occurredAt,
            $this->file, $this->line, $this->frames, $context, $this->tags,
            $this->breadcrumbs, $this->category, $this->fingerprint, $this->fingerprintVersion,
        );
    }
}
