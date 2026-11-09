<?php

namespace FaultNest\Notifications\Channels;

use FaultNest\Contracts\NotificationChannelInterface;
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
            'Location: '.$this->clean($event->file ?? 'n/a').($event->line !== null ? ':'.$event->line : ''),
        ];
        $selected = [];
        foreach ($alert->includeTags as $tag) {
            if (!preg_match('/\A[A-Za-z0-9_-]{1,120}\z/', $tag)) {
                continue;
            }
            $value = $event->tags[$tag] ?? null;
            if (is_string($value)) {
                $selected[] = '  '.$tag.': '.$this->clean(substr($value, 0, 255));
            }
        }
        if ($selected !== []) {
            $lines[] = 'Selected tags:';
            array_push($lines, ...$selected);
        }
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

    protected function clean(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], ' ', $value);
    }
}
