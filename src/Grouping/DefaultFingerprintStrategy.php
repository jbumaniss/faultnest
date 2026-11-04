<?php

namespace FaultNest\Grouping;

use FaultNest\Contracts\FingerprintStrategyInterface;
use FaultNest\Domain\ErrorEvent;

/** Stable grouping from exception type, location and normalized message. */
class DefaultFingerprintStrategy implements FingerprintStrategyInterface
{
    public function version(): int
    {
        return 1;
    }

    public function fingerprint(ErrorEvent $event): string
    {
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '{uuid}', $event->message) ?? $event->message;
        $message = preg_replace('/\b\d+\b/', '{number}', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', strtolower(trim($message))) ?? $message;

        $symbol = $event->frames[0]['call'] ?? '';
        $identity = implode("\0", [
            (string) $this->version(),
            $event->exceptionType ?? 'message',
            $event->file ?? '',
            $symbol,
            $message,
        ]);

        return hash('sha256', $identity);
    }
}
