<?php

namespace FaultNest\Tests\Unit;

use FaultNest\Domain\ErrorLevel;
use FaultNest\Processing\{MonitorOptions, SafePayload};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayloadSafetyTest extends TestCase
{
    protected function payload(array $privacy = []): SafePayload
    {
        return new SafePayload(MonitorOptions::fromArray([
            'enabled' => true, 'project' => 'p', 'environment' => 'e',
            'classification' => ['driver' => 'c'], 'grouping' => ['driver' => 'g'],
            'privacy' => $privacy + ['redact_keys' => ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key', 'apikey', 'api-key']],
        ]));
    }

    /** @return array<string, array{string, string}> */
    public static function secrets(): array
    {
        return [
            'plain' => ['password=hunter2 next', 'hunter2'],
            'colon' => ['token: abc123', 'abc123'],
            'bearer' => ['Authorization: Bearer abc.def.ghi', 'abc.def.ghi'],
            'basic scheme' => ['Authorization: Basic dXNlcjpwYXNz', 'dXNlcjpwYXNz'],
            'prefixed key' => ['access_token=abc123', 'abc123'],
            'prefixed secret' => ['client_secret=abc123', 'abc123'],
            'db password' => ['DB_PASSWORD=abc123', 'abc123'],
            'json' => ['{"password":"hunter2","a":1}', 'hunter2'],
            'single quoted' => ["password='hunter2'", 'hunter2'],
            'query string' => ['GET /x?api_key=abc123&y=1', 'abc123'],
            'dsn' => ['connect mysql://root:s3cr3t@db.internal/app failed', 's3cr3t'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_common_secret_shapes_in_messages_are_redacted(string $message, string $secret): void
    {
        self::assertStringNotContainsString($secret, $this->payload()->message($message));
    }

    public function test_ordinary_messages_are_preserved(): void
    {
        $text = 'Order 42 failed: tokenizer error near line 3';
        self::assertSame($text, $this->payload()->message($text));
    }

    public function test_message_limit_never_produces_invalid_utf8_and_strips_control_breaks(): void
    {
        $result = $this->payload(['max_message_bytes' => 32])->message("line\r\n".str_repeat('é', 200));
        self::assertNotFalse(json_encode($result));
        self::assertStringNotContainsString("\n", $result);
        self::assertLessThanOrEqual(36, strlen($result));
    }

    public function test_invalid_utf8_message_is_stored_safely(): void
    {
        self::assertNotFalse(json_encode($this->payload()->message("bad \xB1\x31 byte")));
    }

    public function test_context_limits_depth_size_key_length_and_types(): void
    {
        $payload = $this->payload(['max_context_bytes' => 128, 'max_context_depth' => 2]);
        foreach ([
            ['x' => str_repeat('y', 200)],
            ['a' => ['b' => ['c' => ['d' => 1]]]],
            [str_repeat('k', 121) => 1],
            ['nan' => NAN],
            ['inf' => INF],
            ['resource' => fopen('php://memory', 'r')],
            ['closure' => fn () => 1],
            ['enum' => ErrorLevel::Error],
        ] as $invalid) {
            try {
                $payload->context($invalid);
                self::fail('Expected rejection: '.array_key_first($invalid));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_redaction_is_case_insensitive_and_recursive_and_round_trips_unicode(): void
    {
        $result = $this->payload()->context(['Nested' => ['X-API-Key' => 'k', 'USER_TOKEN' => 't', 'list' => [['password' => 'p']], 'name' => 'Jūris 你好']]);
        self::assertSame('[REDACTED]', $result['Nested']['X-API-Key']);
        self::assertSame('[REDACTED]', $result['Nested']['USER_TOKEN']);
        self::assertSame('[REDACTED]', $result['Nested']['list'][0]['password']);
        self::assertSame('Jūris 你好', $result['Nested']['name']);
    }
}
