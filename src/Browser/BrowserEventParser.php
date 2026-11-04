<?php

namespace FaultNest\Browser;

use FaultNest\Domain\ErrorLevel;
use InvalidArgumentException;
use JsonException;

/** Strict browser wire schema; never resolves user/HTTP metadata or trusts client identity. */
class BrowserEventParser
{
    public function __construct(
        protected int $maxPayloadBytes = 16384,
        protected ErrorLevel $maxLevel = ErrorLevel::Error,
    ) {
        if ($maxPayloadBytes < 1024 || $maxPayloadBytes > 65536) {
            throw new InvalidArgumentException('Invalid FaultNest browser payload limit.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function parse(array $payload): BrowserEventData
    {
        $allowed = ['schema', 'kind', 'source', 'level', 'message', 'exceptionType', 'stack', 'context', 'tags', 'breadcrumbs'];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown FaultNest browser event field.');
            }
        }
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid FaultNest browser event JSON.', previous: $exception);
        }
        if (strlen($encoded) > $this->maxPayloadBytes) {
            throw new InvalidArgumentException('FaultNest browser payload too large.');
        }

        $kind = $payload['kind'] ?? null;
        $source = $payload['source'] ?? null;
        $level = $payload['level'] ?? null;
        $message = $payload['message'] ?? null;
        $exceptionType = $payload['exceptionType'] ?? null;
        $stack = $payload['stack'] ?? null;
        $context = $payload['context'] ?? [];
        $tags = $payload['tags'] ?? [];
        $breadcrumbs = $payload['breadcrumbs'] ?? [];

        if (($payload['schema'] ?? null) !== 1 || !in_array($kind, ['exception', 'message'], true)
            || !in_array($source, ['browser', 'react'], true)
            || !is_string($level) || ErrorLevel::tryFrom($level) === null
            || !is_string($message) || trim($message) === '' || strlen($message) > 4096
            || ($exceptionType !== null && (!is_string($exceptionType) || strlen($exceptionType) > 255))
            || ($stack !== null && (!is_string($stack) || strlen($stack) > 4096))
            || !is_array($context) || !is_array($tags) || !is_array($breadcrumbs)
            || !array_is_list($breadcrumbs)) {
            throw new InvalidArgumentException('Invalid FaultNest browser event fields.');
        }
        if ($kind === 'message' && ($exceptionType !== null || $stack !== null)) {
            throw new InvalidArgumentException('Message events cannot have an exception stack.');
        }
        if (count($tags) > 32 || count($breadcrumbs) > 20) {
            throw new InvalidArgumentException('FaultNest browser metadata exceeds limits.');
        }
        foreach ($tags as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 120
                || !is_string($value) || strlen($value) > 255) {
                throw new InvalidArgumentException('Invalid FaultNest browser tag.');
            }
        }
        foreach ($breadcrumbs as $breadcrumb) {
            if (!is_array($breadcrumb) || !array_key_exists('message', $breadcrumb) || !array_key_exists('data', $breadcrumb) || count($breadcrumb) !== 2
                || !is_string($breadcrumb['message']) || strlen($breadcrumb['message']) > 512
                || !is_array($breadcrumb['data'])) {
                throw new InvalidArgumentException('Invalid FaultNest browser breadcrumb.');
            }
        }

        $requested = ErrorLevel::from($level);
        $effective = $requested->priority() > $this->maxLevel->priority() ? $this->maxLevel : $requested;

        return new BrowserEventData($source, $kind === 'exception' ? $exceptionType : null,
            $message, $effective, $stack, $context, $tags, $breadcrumbs);
    }
}
