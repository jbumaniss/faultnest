<?php

namespace FaultNest\Tests\Unit;

use FaultNest\Capture\{CaptureScope, ErrorMonitor};
use FaultNest\Contracts\{EventSinkInterface, EventTransformerInterface};
use FaultNest\Domain\{Breadcrumb, CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Grouping\{DefaultCategoryClassifier, DefaultFingerprintStrategy};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions, SafePayload};
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CoreCaptureTest extends TestCase
{
    public function test_manual_context_is_opt_in_and_round_trippable(): void
    {
        [$monitor, $sink] = $this->monitor();
        $first = $monitor->captureMessage('Order 123 missing');
        self::assertSame(CaptureStatus::Captured, $first->status);
        self::assertSame([], $first->event->context);
        self::assertSame([], $first->event->tags);
        self::assertSame([], $first->event->breadcrumbs);

        $monitor->setContext('feature', ['name' => 'orders']);
        $monitor->setTag('team', 'warehouse');
        $monitor->addBreadcrumb(new Breadcrumb('import started', ['batch' => 7]));
        $second = $monitor->captureMessage('Order 456 missing', context: ['user' => ['id' => 15]]);
        self::assertSame(['feature' => ['name' => 'orders'], 'user' => ['id' => 15]], $second->event->context);
        self::assertSame(['team' => 'warehouse'], $second->event->tags);
        self::assertCount(1, $second->event->breadcrumbs);
        self::assertSame($first->event->fingerprint, $second->event->fingerprint);
        self::assertCount(2, $sink->events);

        $monitor->clearScope();
        self::assertSame([], $monitor->captureMessage('third')->event->context);
    }

    public function test_sensitive_explicit_fields_are_redacted_and_objects_rejected(): void
    {
        [$monitor, $sink] = $this->monitor();
        $result = $monitor->captureException(new RuntimeException('token=abc123'), [
            'nested' => ['api_key' => 'secret-value', 'label' => 'allowed'],
        ]);
        self::assertSame(CaptureStatus::Captured, $result->status);
        self::assertSame('token=[REDACTED]', $result->event->message);
        self::assertSame('[REDACTED]', $result->event->context['nested']['api_key']);
        self::assertSame('allowed', $result->event->context['nested']['label']);
        self::assertSame(CaptureStatus::Failed, $monitor->captureMessage('x', context: ['bad' => new \stdClass()])->status);
        self::assertCount(1, $sink->events);
    }

    public function test_disabled_monitor_never_writes_to_sink(): void
    {
        [$monitor, $sink] = $this->monitor(['enabled' => false]);
        self::assertSame(CaptureStatus::Ignored, $monitor->captureMessage('hello')->status);
        self::assertSame([], $sink->events);
    }

    public function test_transformer_can_drop_event_before_sink(): void
    {
        $transformer = new class implements EventTransformerInterface {
            public function transform(ErrorEvent $event): ?ErrorEvent { return null; }
        };
        [$monitor, $sink] = $this->monitor([], [$transformer]);
        self::assertSame(CaptureStatus::Ignored, $monitor->captureMessage('ResizeObserver')->status);
        self::assertSame([], $sink->events);
    }

    public function test_transformer_cannot_override_project_identity_or_bypass_redaction(): void
    {
        $transformer = new class implements EventTransformerInterface {
            public function transform(ErrorEvent $event): ?ErrorEvent {
                return (new ErrorEvent(
                    $event->id, 'attacker', 'wrong', 'browser', ErrorLevel::Critical,
                    $event->exceptionType, 'password=raw-value', $event->occurredAt,
                    $event->file, $event->line, $event->frames, ['token' => 'value'],
                ));
            }
        };
        [$monitor] = $this->monitor([], [$transformer]);
        $event = $monitor->captureMessage('safe')->event;
        self::assertSame('demo', $event->project);
        self::assertSame('production', $event->environment);
        self::assertSame('manual', $event->source);
        self::assertSame('password=[REDACTED]', $event->message);
        self::assertSame('[REDACTED]', $event->context['token']);
    }

    public function test_sink_failures_do_not_escape_to_host(): void
    {
        [$monitor] = $this->monitor([], [], new class implements EventSinkInterface {
            public function record(ErrorEvent $event): void { throw new RuntimeException('sink down'); }
        });
        self::assertSame(CaptureStatus::Failed, $monitor->captureMessage('failure')->status);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<EventTransformerInterface> $transformers
     * @return array{ErrorMonitor, EventSinkInterface&object{events?: array}}
     */
    protected function monitor(array $overrides = [], array $transformers = [], ?EventSinkInterface $sink = null): array
    {
        $config = [
            'enabled' => true, 'project' => 'demo', 'environment' => 'production',
            'capture' => ['exceptions' => true], 'min_level' => 'warning',
            'classification' => ['driver' => DefaultCategoryClassifier::class],
            'grouping' => ['driver' => DefaultFingerprintStrategy::class],
            'privacy' => [
                'max_message_bytes' => 512, 'max_stack_frames' => 20,
                'max_context_bytes' => 8192, 'max_context_depth' => 8,
                'redact_keys' => ['token', 'password', 'api_key'],
            ],
        ];
        $options = MonitorOptions::fromArray(array_replace_recursive($config, $overrides));
        $payload = new SafePayload($options);
        $scope = new CaptureScope();
        $sink ??= new class implements EventSinkInterface {
            /** @var list<ErrorEvent> */
            public array $events = [];
            public function record(ErrorEvent $event): void { $this->events[] = $event; }
        };
        $processor = new EventProcessor(
            $options, $payload, new DefaultCategoryClassifier(), new DefaultFingerprintStrategy(),
            $sink, [], $transformers,
        );
        return [new ErrorMonitor($options, new EventFactory($options, $payload), $processor, fn () => $scope), $sink];
    }
}
