<?php

namespace FaultNest\Processing;

use FaultNest\Contracts\{CategoryClassifierInterface, EventFilterInterface, EventSinkInterface, EventTransformerInterface, FingerprintStrategyInterface};
use FaultNest\Domain\{CaptureResult, ErrorEvent};

/** Orchestrates safe local filtering, transformation, classification and recording. */
class EventProcessor
{
    /**
     * @param list<EventFilterInterface> $filters
     * @param list<EventTransformerInterface> $transformers
     */
    public function __construct(
        protected MonitorOptions $options,
        protected SafePayload $payload,
        protected CategoryClassifierInterface $classifier,
        protected FingerprintStrategyInterface $fingerprint,
        protected EventSinkInterface $sink,
        protected array $filters = [],
        protected array $transformers = [],
    ) {}

    public function process(ErrorEvent $event): CaptureResult
    {
        if (!$event->level->meets($this->options->minLevel)) {
            return CaptureResult::ignored();
        }

        if ($this->isIgnoredType($event->exceptionType)) {
            return CaptureResult::ignored();
        }

        foreach ($this->filters as $filter) {
            if (!$filter->shouldCapture($event)) {
                return CaptureResult::ignored();
            }
        }

        $original = $event;
        foreach ($this->transformers as $transformer) {
            $event = $transformer->transform($event);
            if ($event === null) {
                return CaptureResult::ignored();
            }
        }

        $event = $this->payload->transformed($event, $original);
        if (!$event->level->meets($this->options->minLevel)) {
            return CaptureResult::ignored();
        }

        $category = $this->classifier->classify($event);
        if ($category === '' || strlen($category) > 80) {
            return CaptureResult::failed();
        }

        $fingerprint = $this->fingerprint->fingerprint($event);
        $version = $this->fingerprint->version();
        if ($fingerprint === '' || strlen($fingerprint) > 128 || $version < 1) {
            return CaptureResult::failed();
        }

        $event = $event->withIdentity($category, $fingerprint, $version);
        $this->sink->record($event);

        return CaptureResult::captured($event);
    }

    /** A thrown class is always loaded already, so browser-supplied names can never trigger autoloading. */
    protected function isIgnoredType(?string $type): bool
    {
        if ($type === null) {
            return false;
        }
        $class = strstr($type, '@anonymous', true) ?: $type;
        if (!class_exists($class, false) && !interface_exists($class, false)) {
            return false;
        }
        foreach ($this->options->ignoredExceptions as $ignored) {
            if (is_a($class, $ignored, true)) {
                return true;
            }
        }
        return false;
    }
}
