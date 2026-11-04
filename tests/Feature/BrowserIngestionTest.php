<?php

namespace FaultNest\Tests\Feature;

use FaultNest\Browser\{BrowserEventParser, BrowserEventReceiver};
use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;

/** Untrusted browser input through a host-owned controller and the real monitor pipeline. */
class BrowserIngestionTest extends TestCase
{
    protected EventSinkInterface $sink;

    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = new class implements EventSinkInterface {
            /** @var list<ErrorEvent> */
            public array $events = [];

            public function record(ErrorEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $this->app->instance(EventSinkInterface::class, $this->sink);
        $config = $this->app['config'];
        $config->set('faultnest.enabled', true);
        $config->set('faultnest.storage.enabled', false);
        $config->set('faultnest.project', 'server-project');
        $config->set('faultnest.environment', 'server-env');
        $config->set('faultnest.browser.enabled', true);
    }

    /** @return array<string, mixed> */
    protected function valid(array $overrides = []): array
    {
        return array_replace([
            'schema' => 1, 'kind' => 'exception', 'source' => 'browser', 'level' => 'error',
            'message' => 'boom', 'exceptionType' => 'TypeError',
        ], $overrides);
    }

    protected function accept(array $payload): \FaultNest\Domain\CaptureResult
    {
        return $this->app->make(BrowserEventReceiver::class)->accept($payload);
    }

    public function test_host_owned_route_is_the_only_entrypoint_and_receives_json(): void
    {
        Route::post('/host-errors', function (\Illuminate\Http\Request $request, BrowserEventReceiver $receiver) {
            $result = $receiver->accept($request->json()->all());
            return response()->noContent($result->status === CaptureStatus::Failed ? 422 : 202);
        });
        $this->withHeaders(['Authorization' => 'Bearer private-token'])
            ->postJson('/host-errors', $this->valid())->assertStatus(202);
        $this->postJson('/host-errors', $this->valid(['project' => 'forged']))->assertStatus(422);

        self::assertCount(1, $this->sink->events);
        self::assertSame('server-project', $this->sink->events[0]->project);
        self::assertStringNotContainsString('private-token', (string) json_encode($this->sink->events[0]));
    }

    public function test_client_cannot_exceed_server_severity_ceiling_or_set_identity(): void
    {
        $this->app['config']->set('faultnest.browser.max_level', 'warning');
        $event = $this->accept($this->valid(['level' => 'critical']))->event;
        self::assertSame(ErrorLevel::Warning, $event->level);
        foreach (['project', 'environment', 'user', 'url', 'ip', 'cookies'] as $forbidden) {
            self::assertSame(CaptureStatus::Failed, $this->accept($this->valid([$forbidden => 'x']))->status, $forbidden);
        }
    }

    public function test_stack_is_reduced_to_basename_line_and_call(): void
    {
        $stack = implode("\n", [
            'TypeError: boom token=abc',
            '    at render (https://app.example.test/private/7/app.js?token=secret#frag:43:9)',
            'load@https://app.example.test/assets/vendor.js?v=1:10:2',
            '    at /Users/me/secret/project/main.js:5:1',
        ]);
        $event = $this->accept($this->valid(['stack' => $stack]))->event;
        self::assertSame('app.js', $event->file);
        self::assertSame(43, $event->line);
        self::assertSame('vendor.js', $event->frames[1]['file']);
        self::assertSame('main.js', $event->frames[2]['file']);
        $encoded = (string) json_encode($event->frames);
        foreach (['secret', 'private/7', 'app.example.test', 'Users/me', 'token=', 'frag'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_size_and_shape_limits(): void
    {
        $parser = new BrowserEventParser(1024);
        $this->expectException(InvalidArgumentException::class);
        $parser->parse($this->valid(['context' => ['x' => str_repeat('y', 2048)]]));
    }

    public function test_malformed_payloads_fail_closed_without_reaching_sinks(): void
    {
        foreach ([
            ['schema' => 2], ['schema' => '1'], ['kind' => 'other'], ['source' => 'server'], ['source' => 'http'], ['source' => 'console'], ['source' => 'queue'], ['source' => 'manual'], ['level' => 'fatal'],
            ['message' => ''], ['message' => 12], ['message' => str_repeat('x', 4097)], ['stack' => 5],
            ['exceptionType' => str_repeat('T', 256)], ['context' => 'x'], ['tags' => ['a' => 1]],
            ['tags' => array_fill_keys(array_map(fn ($i) => 'k'.$i, range(1, 33)), 'v')],
            ['breadcrumbs' => [['message' => 'm']]], ['breadcrumbs' => array_fill(0, 21, ['message' => 'm', 'data' => []])],
            ['context' => ['bad' => new \stdClass()]],
        ] as $invalid) {
            self::assertSame(CaptureStatus::Failed, $this->accept($this->valid($invalid))->status, json_encode(array_keys($invalid)));
        }
        self::assertSame(CaptureStatus::Failed, $this->accept(['schema' => 1, 'kind' => 'message', 'source' => 'browser', 'level' => 'error', 'message' => 'm', 'stack' => 'x'])->status);
        self::assertSame([], $this->sink->events);
    }

    public function test_redaction_and_explicit_context_are_server_enforced(): void
    {
        $event = $this->accept($this->valid([
            'message' => 'password=hunter2',
            'context' => ['order' => ['id' => 1], 'session_token' => 'abc'],
            'tags' => ['feature' => 'orders'],
            'breadcrumbs' => [['message' => 'clicked', 'data' => ['api_key' => 'k', 'n' => 1]]],
        ]))->event;
        self::assertSame('password=[REDACTED]', $event->message);
        self::assertSame(['order' => ['id' => 1], 'session_token' => '[REDACTED]'], $event->context);
        self::assertSame('[REDACTED]', $event->breadcrumbs[0]['data']['api_key']);
    }

    public function test_disabled_receiver_ignores_even_valid_events(): void
    {
        $this->app['config']->set('faultnest.browser.enabled', false);
        self::assertSame(CaptureStatus::Ignored, $this->accept($this->valid())->status);
        self::assertSame([], $this->sink->events);
    }

    public function test_globally_disabled_package_ignores_browser_events(): void
    {
        $this->app['config']->set('faultnest.enabled', false);
        self::assertSame(CaptureStatus::Ignored, $this->accept($this->valid())->status);
    }

    // ---- Regression tests for defects found in review ----

    public function test_untrusted_exception_type_cannot_carry_control_characters_into_storage_or_mail(): void
    {
        $event = $this->accept($this->valid(['exceptionType' => "Bad\0Type\r\nX-Injected: 1"]))->event;
        self::assertSame('Bad Type  X-Injected: 1', $event->exceptionType);
    }

    public function test_absurd_stack_line_numbers_are_dropped_not_stored_out_of_range(): void
    {
        $event = $this->accept($this->valid(['stack' => 'at x (https://a.test/a.js:99999999999999999999999:1)']))->event;
        self::assertLessThanOrEqual(2147483647, $event->frames[0]['line']);
        self::assertLessThanOrEqual(2147483647, (int) $event->line);
    }

    public function test_manager_exposes_same_receiver_and_binding_stays_lazy(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        self::assertInstanceOf(BrowserEventReceiver::class, $manager->browserReceiver());
    }
}
