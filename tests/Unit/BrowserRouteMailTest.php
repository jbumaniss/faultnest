<?php

namespace FaultNest\Tests\Unit;

use DateTimeImmutable;
use FaultNest\Domain\ErrorEvent;
use FaultNest\Domain\ErrorLevel;
use FaultNest\Notifications\Alert;
use FaultNest\Notifications\Channels\MailChannel;
use FaultNest\Notifications\DeliveryTarget;
use Illuminate\Contracts\Mail\Mailer;
use PHPUnit\Framework\TestCase;

class BrowserRouteMailTest extends TestCase
{
    public function test_browser_email_shows_bounded_route_and_location_without_enabling_full_context(): void
    {
        $body = $this->render('react', [
            'route' => 'Review/Show',
            'location' => 'ReviewShowForm-CH8tkk1B.js:1:2345',
        ]);

        $this->assertStringContainsString('Route: Review/Show', $body);
        $this->assertStringContainsString('Location: ReviewShowForm-CH8tkk1B.js:1:2345', $body);
        $this->assertStringNotContainsString('Explicit context:', $body);
    }

    public function test_untrusted_route_or_location_never_appears_in_email(): void
    {
        $body = $this->render('browser', [
            'route' => 'https://example.test/reviews?token=secret',
            'location' => 'https://example.test/private.js:12:3?token=secret',
        ]);

        $this->assertStringNotContainsString('Route:', $body);
        $this->assertStringContainsString('Location: n/a', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    public function test_non_browser_sources_ignore_client_tags(): void
    {
        $body = $this->render('http', [
            'route' => 'Review/Show',
            'location' => 'app.js:1:22',
        ]);

        $this->assertStringNotContainsString('Route:', $body);
        $this->assertStringContainsString('Location: n/a', $body);
    }

    public function test_legacy_browser_event_remains_supported(): void
    {
        $body = $this->render('browser', []);

        $this->assertStringContainsString('Location: n/a', $body);
        $this->assertStringNotContainsString('Route:', $body);
    }

    /** @param array<string, string> $tags */
    private function render(string $source, array $tags): string
    {
        $event = new ErrorEvent(
            id: 'test-event',
            project: 'test',
            environment: 'testing',
            source: $source,
            level: ErrorLevel::Error,
            exceptionType: 'Error',
            message: 'Rejected',
            occurredAt: new DateTimeImmutable('2026-11-08T23:00:47+00:00'),
            file: null,
            line: null,
            tags: $tags,
        );

        $body = null;
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())->method('raw')
            ->willReturnCallback(static function (string $text) use (&$body): void {
                $body = $text;
            });

        (new MailChannel($mailer))->send(new Alert($event), new DeliveryTarget('support@example.test'));

        $this->assertIsString($body);

        return $body;
    }
}
