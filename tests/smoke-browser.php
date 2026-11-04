<?php

// Dependency-free browser receiver smoke test, does not emulate Laravel routing/auth.
spl_autoload_register(function (string $class): void {
    $prefix = 'FaultNest\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});

use FaultNest\Browser\{BrowserEventParser, BrowserEventReceiver};
use FaultNest\Capture\{CaptureScope, ErrorMonitor};
use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Grouping\{DefaultCategoryClassifier, DefaultFingerprintStrategy};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions, SafePayload};

$options = MonitorOptions::fromArray([
    'enabled' => true, 'project' => 'server-project', 'environment' => 'staging',
    'min_level' => 'warning',
    'classification' => ['driver' => DefaultCategoryClassifier::class],
    'grouping' => ['driver' => DefaultFingerprintStrategy::class],
    'privacy' => ['redact_keys' => ['token', 'password']],
]);
$payload = new SafePayload($options);
$scope = new CaptureScope();
$sink = new class implements EventSinkInterface {
    public array $events = [];
    public function record(ErrorEvent $event): void { $this->events[] = $event; }
};
$monitor = new ErrorMonitor($options,
    new EventFactory($options, $payload),
    new EventProcessor($options, $payload, new DefaultCategoryClassifier(), new DefaultFingerprintStrategy(), $sink),
    fn () => $scope,
);
$receiver = new BrowserEventReceiver($monitor, new BrowserEventParser(), true);
$event = [
    'schema' => 1, 'kind' => 'exception', 'source' => 'react',
    'level' => 'critical', 'message' => 'password=secret-value',
    'exceptionType' => 'TypeError',
    'stack' => "TypeError: fail\n  at render (https://example.com/js/app.js?token=should-not-persist:43:9)",
    'context' => ['other' => ['id' => 4], 'token' => 'secret'],
    'tags' => ['feature' => 'orders'],
    'breadcrumbs' => [['message' => 'selected item', 'data' => ['id' => 7]]],
];
$result = $receiver->accept($event);
assert($result->status === CaptureStatus::Captured);
assert($result->event->project === 'server-project');
assert($result->event->environment === 'staging');
assert($result->event->level === ErrorLevel::Error);
assert($result->event->source === 'react');
assert($result->event->message === 'password=[REDACTED]');
assert($result->event->context['token'] === '[REDACTED]');
assert($result->event->context['other']['id'] === 4);
assert($result->event->tags['feature'] === 'orders');
assert($result->event->file === 'app.js');
assert(strpos(json_encode($result->event->frames), 'secret') === false);
assert(count($sink->events) === 1);
assert($receiver->accept(array_merge($event, ['project' => 'attacker']))->status === CaptureStatus::Failed);
assert($receiver->accept(array_merge($event, ['level' => 'info']))->status === CaptureStatus::Ignored);
assert((new BrowserEventReceiver($monitor, new BrowserEventParser(), false))->accept($event)->status === CaptureStatus::Ignored);
assert($receiver->accept(array_merge($event, ['context' => ['bad' => new stdClass()]]))->status === CaptureStatus::Failed);
echo "PASS: server-side browser receiver, host identity, opt-in context, severity cap, redaction, schema validation\n";
