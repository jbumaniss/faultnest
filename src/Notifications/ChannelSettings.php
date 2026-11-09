<?php

namespace FaultNest\Notifications;

use FaultNest\Domain\{ErrorEvent, ErrorLevel};
use InvalidArgumentException;

/** Validated delivery configuration for one named channel. */
readonly class ChannelSettings
{
    /** Browser sources need an explicit per-channel opt-in, because anyone reaching a host endpoint can send them. */
    public const DEFAULT_ALLOWED_SOURCES = ['http', 'console', 'queue', 'manual'];

    /**
     * @param list<string> $recipients
     * @param array<string, int> $levelCooldowns
     * @param list<string> $allowedSources
     * @param list<string> $includeTags
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $driver,
        public ?string $recipientResolver,
        public array $recipients,
        public ErrorLevel $minLevel,
        public int $cooldownSeconds,
        public array $levelCooldowns,
        public int $pendingTtlSeconds,
        public int $tries,
        public int $backoffSeconds,
        public ?string $cacheStore,
        public ?string $queueConnection,
        public ?string $queue,
        public bool $allowSync,
        public bool $includeContext,
        public array $allowedSources = self::DEFAULT_ALLOWED_SOURCES,
        public array $includeTags = [],
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(string $name, array $config): self
    {
        $driver = $config['driver'] ?? null;
        $resolver = $config['recipient_resolver'] ?? null;
        $recipients = $config['recipients'] ?? [];
        $level = $config['min_level'] ?? 'error';
        $cooldown = $config['cooldown_seconds'] ?? 1800;
        $perLevel = $config['level_cooldowns'] ?? [];
        $pendingTtl = $config['pending_ttl_seconds'] ?? 3600;
        $tries = $config['tries'] ?? 3;
        $backoff = $config['backoff_seconds'] ?? 60;
        $store = $config['cache_store'] ?? null;
        $connection = $config['queue_connection'] ?? null;
        $queue = $config['queue'] ?? null;
        $sources = $config['allowed_sources'] ?? self::DEFAULT_ALLOWED_SOURCES;
        $includeTags = $config['include_tags'] ?? [];
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $name)
            || !is_bool($config['enabled'] ?? false)
            || !is_string($driver) || $driver === ''
            || ($resolver !== null && (!is_string($resolver) || $resolver === ''))
            || !is_array($recipients) || !array_is_list($recipients)
            || ErrorLevel::tryFrom($level) === null
            || !is_int($cooldown) || $cooldown < 0 || $cooldown > 604800
            || !is_array($perLevel) || !is_int($pendingTtl) || $pendingTtl < 60 || $pendingTtl > 604800
            || !is_int($tries) || $tries < 1 || $tries > 10
            || !is_int($backoff) || $backoff < 1 || $backoff > 3600
            || ($store !== null && (!is_string($store) || $store === ''))
            || ($connection !== null && (!is_string($connection) || $connection === ''))
            || ($queue !== null && (!is_string($queue) || $queue === ''))
            || !is_bool($config['allow_sync'] ?? false)
            || !is_bool($config['include_context'] ?? false)
            || !is_array($sources) || !array_is_list($sources)
            || !is_array($includeTags) || !array_is_list($includeTags) || count($includeTags) > 32) {
            throw new InvalidArgumentException('Invalid FaultNest notification channel settings: '.$name);
        }
        foreach ($recipients as $recipient) {
            if (!is_string($recipient) || trim($recipient) === '' || strlen($recipient) > 320) {
                throw new InvalidArgumentException('Invalid FaultNest notification recipient.');
            }
        }
        foreach ($sources as $source) {
            if (!is_string($source) || !preg_match('/^[a-z0-9_-]{1,32}$/', $source)) {
                throw new InvalidArgumentException('Invalid FaultNest notification source.');
            }
        }
        foreach ($includeTags as $tag) {
            if (!is_string($tag) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/', $tag)) {
                throw new InvalidArgumentException('Invalid FaultNest notification tag allowlist.');
            }
        }
        if (count(array_unique($includeTags)) !== count($includeTags)) {
            throw new InvalidArgumentException('Duplicate FaultNest notification tag allowlist entry.');
        }
        foreach ($perLevel as $levelName => $seconds) {
            if (!is_string($levelName) || ErrorLevel::tryFrom($levelName) === null
                || !is_int($seconds) || $seconds < 0 || $seconds > 604800) {
                throw new InvalidArgumentException('Invalid FaultNest level cooldown.');
            }
        }
        return new self(
            $name, $config['enabled'] ?? false, $driver, $resolver, $recipients,
            ErrorLevel::from($level), $cooldown, $perLevel, $pendingTtl, $tries, $backoff,
            $store, $connection, $queue, $config['allow_sync'] ?? false, $config['include_context'] ?? false,
            $sources, $includeTags,
        );
    }

    /** Delivery policy shared by every channel driver. */
    public function accepts(ErrorEvent $event): bool
    {
        return $this->enabled
            && $event->level->meets($this->minLevel)
            && in_array($event->source, $this->allowedSources, true);
    }

    public function cooldownFor(ErrorLevel $level): int
    {
        return $this->levelCooldowns[$level->value] ?? $this->cooldownSeconds;
    }
}
