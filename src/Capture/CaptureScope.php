<?php

namespace FaultNest\Capture;

use FaultNest\Domain\Breadcrumb;

/** Scoped to the current Laravel request/job; never populated automatically. */
class CaptureScope
{
    /** Keeps long-lived scopes bounded; only the most recent breadcrumbs matter. */
    public const MAX_BREADCRUMBS = 50;

    /** @var array<string, mixed> */
    private array $context = [];
    /** @var array<string, string> */
    private array $tags = [];
    /** @var list<Breadcrumb> */
    private array $breadcrumbs = [];
    private bool $processing = false;

    public function setContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $this->breadcrumbs[] = $breadcrumb;
        if (count($this->breadcrumbs) > self::MAX_BREADCRUMBS) {
            array_shift($this->breadcrumbs);
        }
    }

    /** @return array<string, mixed> */
    public function context(): array { return $this->context; }
    /** @return array<string, string> */
    public function tags(): array { return $this->tags; }
    /** @return list<Breadcrumb> */
    public function breadcrumbs(): array { return $this->breadcrumbs; }

    public function enter(): bool
    {
        if ($this->processing) {
            return false;
        }

        $this->processing = true;
        return true;
    }

    public function leave(): void
    {
        $this->processing = false;
    }

    public function clear(): void
    {
        $this->context = [];
        $this->tags = [];
        $this->breadcrumbs = [];
        $this->processing = false;
    }
}
