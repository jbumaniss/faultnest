<?php

namespace FaultNest\Processing;

use FaultNest\Domain\ErrorLevel;
use InvalidArgumentException;

/** Validated, immutable runtime configuration for the capture milestone. */
readonly class MonitorOptions
{
    /**
     * @param list<class-string> $ignoredExceptions
     * @param list<class-string> $filters
     * @param list<class-string> $transformers
     * @param list<string> $redactKeys
     */
    public function __construct(
        public bool $enabled,
        public bool $captureExceptions,
        public string $project,
        public string $environment,
        public ErrorLevel $minLevel,
        public array $ignoredExceptions,
        public array $filters,
        public array $transformers,
        public string $classifier,
        public string $fingerprintStrategy,
        public int $maxMessageBytes,
        public int $maxStackFrames,
        public int $maxContextBytes,
        public int $maxContextDepth,
        public array $redactKeys,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $privacy = $config['privacy'] ?? [];
        $capture = $config['capture'] ?? [];
        $classification = $config['classification'] ?? [];
        $grouping = $config['grouping'] ?? [];

        if (!is_array($privacy) || !is_array($capture) || !is_array($classification) || !is_array($grouping)) {
            throw new InvalidArgumentException('Invalid FaultNest configuration section.');
        }

        $level = ErrorLevel::tryFrom($config['min_level'] ?? 'warning');
        $project = $config['project'] ?? null;
        $environment = $config['environment'] ?? null;
        $maxMessage = $privacy['max_message_bytes'] ?? 512;
        $maxFrames = $privacy['max_stack_frames'] ?? 20;
        $maxContext = $privacy['max_context_bytes'] ?? 8192;
        $maxDepth = $privacy['max_context_depth'] ?? 8;

        if ($level === null || !is_string($project) || trim($project) === '' || strlen($project) > 120
            || !is_string($environment) || trim($environment) === '' || strlen($environment) > 120
            || !is_int($maxMessage) || $maxMessage < 32 || $maxMessage > 4096
            || !is_int($maxFrames) || $maxFrames < 0 || $maxFrames > 50
            || !is_int($maxContext) || $maxContext < 128 || $maxContext > 65536
            || !is_int($maxDepth) || $maxDepth < 1 || $maxDepth > 16) {
            throw new InvalidArgumentException('Invalid FaultNest capture settings.');
        }

        $ignored = self::stringList($config['ignore_exceptions'] ?? []);
        $filters = self::stringList($config['filters'] ?? []);
        $transformers = self::stringList($config['transformers'] ?? []);
        $redactKeys = self::stringList($privacy['redact_keys'] ?? []);
        $classifier = $classification['driver'] ?? null;
        $fingerprint = $grouping['driver'] ?? null;

        if (!is_string($classifier) || $classifier === '' || !is_string($fingerprint) || $fingerprint === ''
            || !is_bool($config['enabled'] ?? false) || !is_bool($capture['exceptions'] ?? true)) {
            throw new InvalidArgumentException('Invalid FaultNest integrations.');
        }

        return new self(
            $config['enabled'], $capture['exceptions'] ?? true, $project, $environment, $level,
            $ignored, $filters, $transformers, $classifier, $fingerprint,
            $maxMessage, $maxFrames, $maxContext, $maxDepth, $redactKeys,
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('Expected a list of class names or strings.');
        }

        foreach ($list as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException('FaultNest list entries must be nonempty strings.');
            }
        }

        return $list;
    }
}
