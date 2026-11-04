<?php

namespace FaultNest\Storage;

use FaultNest\Domain\ErrorEvent;

/** Stable identity shared by host Cache and Database group stores. */
class GroupIdentity
{
    public static function cacheKey(string $project, string $environment, int $version, string $fingerprint): string
    {
        return 'faultnest:group:v1:'.hash('sha256', json_encode(
            [$project, $environment, $version, $fingerprint], JSON_THROW_ON_ERROR,
        ));
    }

    public static function id(ErrorEvent $event): string
    {
        return hash('sha256', self::cacheKey(
            $event->project, $event->environment, $event->fingerprintVersion, $event->fingerprint,
        ));
    }
}
