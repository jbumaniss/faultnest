<?php

namespace FaultNest\Notifications\Channels;

use FaultNest\Contracts\NotificationChannelInterface;
use FaultNest\Domain\ErrorEvent;
use FaultNest\Notifications\{Alert, DeliveryTarget};
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use InvalidArgumentException;

/** Sends one host-authorized, plain-text message per destination. */
class MailChannel implements NotificationChannelInterface
{
    public function __construct(protected Mailer $mailer) {}

    public function send(Alert $alert, DeliveryTarget $target): void
    {
        if (!filter_var($target->value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('FaultNest mail target is not a valid email address.');
        }
        $event = $alert->event;
        $route = $this->browserTag($event, 'route', '/\A[A-Za-z][A-Za-z0-9_-]*(?:\/[A-Za-z][A-Za-z0-9_-]*)*\z/');
        $browserLocation = $this->browserTag($event, 'location', '/\A[A-Za-z0-9_.-]+\.(?:mjs|js|jsx|ts|tsx):[1-9]\d{0,8}:\d{1,9}\z/');
        $browser = $this->browserTag($event, 'browser', '/\A(?:Edge|Opera|Firefox|Chrome|Safari) [0-9]{1,3}\z/');
        $authId = $this->browserTag($event, 'auth_id', '/\A(?:guest|[0-9]{1,20}|[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12})\z/');
        $subject = sprintf('[FaultNest][%s][%s][%s] %s [%s]',
            $this->clean($event->project), $this->clean($event->environment),
            strtoupper($event->level->value),
            $this->clean($event->exceptionType ?? $event->category),
            substr($event->fingerprint, 0, 12),
        );
        $lines = [
            'Reporter: FaultNest',
            'Project: '.$this->clean($event->project),
            'Environment: '.$this->clean($event->environment),
            'Source: '.$this->clean($event->source),
            'Level: '.$event->level->value,
            'Category: '.$this->clean($event->category),
            'Event ID: '.$event->id,
            'Fingerprint: '.$event->fingerprint,
            'Exception: '.$this->clean($event->exceptionType ?? 'manual message'),
            'Time (UTC): '.$event->occurredAt->format(DATE_ATOM),
            'Message: '.$this->clean($event->message),
        ];
        if ($route !== null) {
            $lines[] = 'Route: '.$route;
        }
        if ($browser !== null) {
            $lines[] = 'Browser: '.$browser;
        }
        if ($authId !== null) {
            $lines[] = 'Auth ID: '.$authId;
        }
        $lines[] = 'Location: '.($browserLocation ?? ($this->clean($event->file ?? 'n/a')
            .($event->line !== null ? ':'.$event->line : '')));
        foreach ($event->frames as $frame) {
            $lines[] = '  '.$this->clean($frame['file']).':'.$frame['line'].' '.$this->clean($frame['call']);
        }
        if ($alert->includeContext) {
            $lines[] = 'Explicit context: '.json_encode([
                'context' => $event->context,
                'tags' => $event->tags,
                'breadcrumbs' => $event->breadcrumbs,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $body = implode("\r\n", $lines);
        $this->mailer->raw($body, function (Message $message) use ($target, $subject): void {
            $message->to($target->value)->subject($subject);
        });
    }

    /**
     * Browser tags are untrusted. Only render narrowly formatted, non-URL
     * diagnostics in mail, regardless of the general include_context setting.
     */
    protected function browserTag(ErrorEvent $event, string $key, string $pattern): ?string
    {
        if (!in_array($event->source, ['browser', 'react'], true)) {
            return null;
        }

        $value = $event->tags[$key] ?? null;

        return is_string($value) && strlen($value) <= 255 && preg_match($pattern, $value) === 1
            ? $value
            : null;
    }

    protected function clean(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], ' ', $value);
    }
}
