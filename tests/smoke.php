<?php

// Dependency-free core smoke test while Composer / PHPUnit are unavailable.
spl_autoload_register(function (string $class): void {
    $prefix = 'FaultNest\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});

use FaultNest\Capture\{CaptureScope, ErrorMonitor};
use FaultNest\Contracts\EventSinkInterface;
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Grouping\{DefaultCategoryClassifier, DefaultFingerprintStrategy};
use FaultNest\Processing\{EventFactory, EventProcessor, MonitorOptions, SafePayload};

$config = [
    'enabled' => true, 'project' => 'smoke', 'environment' => 'testing',
    'capture' => ['exceptions' => true], 'min_level' => 'warning',
    'classification' => ['driver' => DefaultCategoryClassifier::class],
    'grouping' => ['driver' => DefaultFingerprintStrategy::class],
    'privacy' => ['redact_keys' => ['token', 'password']],
];
$options = MonitorOptions::fromArray($config);
$scope = new CaptureScope();
$payload = new SafePayload($options);
$sink = new class implements EventSinkInterface {
    public array $events = [];
    public function record(ErrorEvent $event): void { $this->events[] = $event; }
};
$processor = new EventProcessor($options, $payload, new DefaultCategoryClassifier(), new DefaultFingerprintStrategy(), $sink);
$monitor = new ErrorMonitor($options, new EventFactory($options, $payload), $processor, fn () => $scope);
$first = $monitor->captureMessage('Order 123 not found');
$monitor->setContext('custom', ['order' => 456, 'token' => 'do-not-store']);
$second = $monitor->captureMessage('Order 456 not found');
$failed = $monitor->captureMessage('invalid', context: ['object' => new stdClass()]);
$monitor->clearScope();
$third = $monitor->captureMessage('another event', ErrorLevel::Warning);

assert($first->status === CaptureStatus::Captured);
assert($second->status === CaptureStatus::Captured);
assert($first->event->fingerprint === $second->event->fingerprint);
assert($first->event->context === []);
assert($second->event->context['custom']['order'] === 456);
assert($second->event->context['custom']['token'] === '[REDACTED]');
assert($failed->status === CaptureStatus::Failed);
assert($third->event->context === []);
assert(count($sink->events) === 3);
echo "PASS: capture, grouping, opt-in context, redaction, failure isolation, scope reset\n";
