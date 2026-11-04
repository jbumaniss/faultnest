<?php

namespace FaultNest\Processing;

use FaultNest\Domain\ErrorEvent;
use InvalidArgumentException;
use JsonException;

/** Validates and minimizes explicit context; never inspects request/auth state or objects. */
class SafePayload
{
    public function __construct(protected MonitorOptions $options) {}

    public function message(string $message): string
    {
        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message) ?? '[unreadable]';
        $message = preg_replace(
            '/([\w.-]*(?:password|passwd|pwd|token|secret|authorization|api[_-]?key)[\w.-]*)["\']?\s*[:=]\s*(?:(?:basic|digest|bearer)\s+)?(?:"[^"]*"|\'[^\']*\'|[^\s,;&"\']+)/i',
            '$1=[REDACTED]', $message,
        ) ?? '[unreadable]';
        $message = preg_replace('~\b([a-z][a-z0-9+.-]*://[^\s:/@]*):[^\s/@]+@~i', '$1:[REDACTED]@', $message) ?? '[unreadable]';
        $message = str_replace(["\r", "\n", "\0"], ' ', $message);

        $message = substr($message, 0, $this->options->maxMessageBytes);
        return json_decode(json_encode($message, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        $normalized = $this->normalize($context, 0);
        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        if (strlen($encoded) > $this->options->maxContextBytes) {
            throw new InvalidArgumentException('FaultNest explicit context exceeds the configured byte limit.');
        }

        return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Revalidates transformed data and preserves server-authoritative identity.
     */
    public function transformed(ErrorEvent $candidate, ErrorEvent $original): ErrorEvent
    {
        $tags = $this->normalize($candidate->tags, 0);
        $breadcrumbs = $this->normalize($candidate->breadcrumbs, 0);
        $explicit = $this->context([
            'context' => $candidate->context,
            'tags' => $tags,
            'breadcrumbs' => $breadcrumbs,
        ]);

        return new ErrorEvent(
            $original->id, $original->project, $original->environment, $original->source,
            $candidate->level, $original->exceptionType, $this->message($candidate->message),
            $original->occurredAt, $original->file, $original->line, $original->frames,
            $explicit['context'], $explicit['tags'], $explicit['breadcrumbs'],
        );
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > $this->options->maxContextDepth) {
            throw new InvalidArgumentException('FaultNest explicit context exceeds maximum nesting.');
        }

        if (is_array($value)) {
            $output = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && strlen($key) > 120) {
                    throw new InvalidArgumentException('FaultNest context key is too long.');
                }

                $sensitive = is_string($key) && $this->isSensitive($key);
                $output[$key] = $sensitive ? '[REDACTED]' : $this->normalize($item, $depth + 1);
            }

            return $output;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        throw new InvalidArgumentException('FaultNest context accepts only JSON-compatible primitives and arrays.');
    }

    protected function isSensitive(string $key): bool
    {
        foreach ($this->options->redactKeys as $needle) {
            if (stripos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
