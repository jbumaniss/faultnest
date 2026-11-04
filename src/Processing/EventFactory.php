<?php

namespace FaultNest\Processing;

use DateTimeImmutable;
use FaultNest\Browser\BrowserEventData;
use FaultNest\Domain\Breadcrumb;
use DateTimeZone;
use ErrorException;
use FaultNest\Capture\CaptureScope;
use FaultNest\Domain\ErrorEvent;
use FaultNest\Domain\ErrorLevel;
use Throwable;

/** Extracts intrinsic Throwable diagnostics only. Never reads Laravel Request/Auth. */
class EventFactory
{
    public function __construct(
        protected MonitorOptions $options,
        protected SafePayload $payload,
        protected ?string $projectRoot = null,
    ) {}

    /** @param array<string, mixed> $context */
    public function exception(Throwable $exception, CaptureScope $scope, array $context, ?ErrorLevel $level, string $source): ErrorEvent
    {
        $frames = [];
        foreach (array_slice($exception->getTrace(), 0, $this->options->maxStackFrames) as $frame) {
            $frames[] = [
                'file' => $this->safePath($frame['file'] ?? ''),
                'line' => (int) ($frame['line'] ?? 0),
                'call' => substr((string) (($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '')), 0, 180),
            ];
        }

        $severity = $level ?? ($exception instanceof ErrorException && in_array(
            $exception->getSeverity(), [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true,
        ) ? ErrorLevel::Warning : ErrorLevel::Error);

        return $this->build(
            $exception::class, $exception->getMessage(), $severity, $this->safePath($exception->getFile()),
            $exception->getLine(), $frames, $scope, $context, $source,
        );
    }

    /** Browser events contain only diagnostics and context explicitly supplied by app code. */
    public function browser(BrowserEventData $data, CaptureScope $scope): ErrorEvent
    {
        $browserScope = new CaptureScope();
        foreach ($scope->context() as $key => $value) {
            $browserScope->setContext($key, $value);
        }
        foreach (array_replace($scope->tags(), $data->tags) as $key => $value) {
            $browserScope->setTag($key, $value);
        }
        foreach ($scope->breadcrumbs() as $breadcrumb) {
            $browserScope->addBreadcrumb($breadcrumb);
        }
        foreach ($data->breadcrumbs as $breadcrumb) {
            $browserScope->addBreadcrumb(new Breadcrumb($breadcrumb['message'], $breadcrumb['data']));
        }

        $frames = $this->browserFrames($data->stack);
        $first = $frames[0] ?? null;
        return $this->build($data->exceptionType, $data->message, $data->level,
            $first['file'] ?? null, $first['line'] ?? null, $frames,
            $browserScope, $data->context, $data->source);
    }

    /** @return list<array{file:string,line:int,call:string}> */
    protected function browserFrames(?string $stack): array
    {
        if ($stack === null) {
            return [];
        }
        $frames = [];
        foreach (preg_split('/\R/', $stack) ?: [] as $frame) {
            if (count($frames) >= $this->options->maxStackFrames) {
                break;
            }
            if (!preg_match('~(?<path>(?:https?://|file://)[^\s()]+|/[^\s()]+):(?<line>\d+):\d+~', $frame, $matches)) {
                continue;
            }
            $path = (string) (parse_url($matches['path'], PHP_URL_PATH) ?: $matches['path']);
            $name = substr(basename($path), 0, 255);
            $call = trim(str_replace($matches[0], '', $frame), " \t()");
            $frames[] = [
                'file' => $name,
                'line' => max(0, (int) $matches['line']),
                'call' => substr($this->payload->message($call), 0, 180),
            ];
        }
        return $frames;
    }

    /** @param array<string, mixed> $context */
    public function message(string $message, ErrorLevel $level, CaptureScope $scope, array $context): ErrorEvent
    {
        return $this->build(null, $message, $level, null, null, [], $scope, $context, 'manual');
    }

    /**
     * @param list<array{file:string,line:int,call:string}> $frames
     * @param array<string, mixed> $context
     */
    protected function build(?string $type, string $message, ErrorLevel $level, ?string $file, ?int $line, array $frames, CaptureScope $scope, array $context, string $source): ErrorEvent
    {
        $rawBreadcrumbs = [];
        foreach ($scope->breadcrumbs() as $breadcrumb) {
            $rawBreadcrumbs[] = ['message' => $breadcrumb->message, 'data' => $breadcrumb->data];
        }

        $explicit = $this->payload->context([
            'context' => array_replace($scope->context(), $context),
            'tags' => $scope->tags(),
            'breadcrumbs' => $rawBreadcrumbs,
        ]);

        return new ErrorEvent(
            bin2hex(random_bytes(16)), $this->options->project, $this->options->environment, $source,
            $level, $type, $this->payload->message($message),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $file, $line, $frames, $explicit['context'], $explicit['tags'], $explicit['breadcrumbs'],
        );
    }

    protected function safePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $this->projectRoot ?? ''), '/');

        if ($root !== '' && str_starts_with($path, $root.'/')) {
            return substr($path, strlen($root) + 1, 255);
        }

        return substr(basename($path), 0, 255);
    }
}
