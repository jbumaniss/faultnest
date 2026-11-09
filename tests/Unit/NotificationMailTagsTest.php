<?php

namespace FaultNest\Tests\Unit;

use DateTimeImmutable;
use FaultNest\Domain\ErrorEvent;
use FaultNest\Domain\ErrorLevel;
use FaultNest\Notifications\Alert;
use FaultNest\Notifications\ChannelSettings;
use FaultNest\Notifications\Channels\MailChannel;
use FaultNest\Notifications\DeliveryTarget;
use Illuminate\Contracts\Mail\Mailer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NotificationMailTagsTest extends TestCase
{
    public function test_default_mail_does_not_include_optional_tags_or_context(): void
    {
        $body = $this->render();

        $this->assertStringContainsString('Location: n/a', $body);
        $this->assertStringNotContainsString('Selected tags:', $body);
        $this->assertStringNotContainsString('Explicit context:', $body);
    }

    public function test_explicit_allowlist_only_displays_selected_tags(): void
    {
        $body = $this->render(['module', 'request_id']);

        $this->assertStringContainsString("Selected tags:\r\n  module: inventory\r\n  request_id: ABC-42", $body);
        $this->assertStringNotContainsString('  private_note:', $body);
        $this->assertStringNotContainsString('Explicit context:', $body);
    }

    public function test_unknown_selected_tag_is_omitted(): void
    {
        $body = $this->render(['missing']);

        $this->assertStringNotContainsString('Selected tags:', $body);
    }

    public function test_mail_tag_value_cannot_insert_new_mail_lines(): void
    {
        $body = $this->render(['private_note'], "Hello\r\nBcc: fake@example.test");

        $this->assertStringContainsString('  private_note: Hello  Bcc: fake@example.test', $body);
        $this->assertStringNotContainsString("\r\nBcc:", $body);
    }

    public function test_notification_channel_settings_accept_explicit_tag_allowlist(): void
    {
        $settings = ChannelSettings::fromArray('mail', $this->settings(['module', 'request_id']));

        $this->assertSame(['module', 'request_id'], $settings->includeTags);
    }

    public function test_notification_channel_settings_default_to_empty_tag_allowlist(): void
    {
        $settings = ChannelSettings::fromArray('mail', $this->settings());

        $this->assertSame([], $settings->includeTags);
    }

    public function test_duplicate_and_invalid_tag_allowlists_are_rejected(): void
    {
        foreach ([['module', 'module'], ['route?'], [''], [42], 'module', range(1, 33)] as $invalid) {
            try {
                ChannelSettings::fromArray('mail', $this->settings($invalid));
                $this->fail('Invalid tag allowlist must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param mixed $tags */
    private function settings(mixed $tags = null): array
    {
        $settings = [
            'enabled' => true,
            'driver' => MailChannel::class,
            'include_context' => false,
        ];

        if ($tags !== null) {
            $settings['include_tags'] = $tags;
        }

        return $settings;
    }

    /** @param list<string> $includeTags */
    private function render(array $includeTags = [], string $privateNote = 'should not appear'): string
    {
        $event = new ErrorEvent(
            id: 'test-event',
            project: 'test',
            environment: 'testing',
            source: 'manual',
            level: ErrorLevel::Error,
            exceptionType: 'Error',
            message: 'Example failed',
            occurredAt: new DateTimeImmutable('2026-11-08T23:00:47+00:00'),
            file: null,
            line: null,
            context: ['secret' => 'never send'],
            tags: [
                'module' => 'inventory',
                'request_id' => 'ABC-42',
                'private_note' => $privateNote,
            ],
        );

        $body = null;
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())->method('raw')
            ->willReturnCallback(static function (string $text) use (&$body): void {
                $body = $text;
            });

        (new MailChannel($mailer))->send(
            new Alert($event, includeTags: $includeTags),
            new DeliveryTarget('support@example.test'),
        );

        $this->assertIsString($body);

        return $body;
    }
}
