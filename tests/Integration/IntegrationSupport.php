<?php

namespace FaultNest\Tests\Integration;

/** Runs real OS processes concurrently and aggregates their JSON results. */
trait IntegrationSupport
{
    /** @return array{ok:int,failed:int,errors:array<string,string>,won:int} */
    protected function runWorkers(string $mode, int $workers, int $each, string $fingerprint, array $env = []): array
    {
        $env += ['IT_START_AT' => (string) (microtime(true) + 1.5)];
        $handles = [];
        for ($i = 0; $i < $workers; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, __DIR__.'/bin/worker.php', $mode, (string) $each, $fingerprint],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv(),
            );
            self::assertIsResource($process);
            $handles[] = [$process, $pipes];
        }
        $total = ['ok' => 0, 'failed' => 0, 'errors' => [], 'won' => 0];
        foreach ($handles as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($process);
            $line = json_decode((string) $out, true);
            self::assertIsArray($line, 'Worker crashed: '.$out.$err);
            $total['ok'] += $line['ok'];
            $total['failed'] += $line['failed'];
            $total['won'] += $line['won'];
            $total['errors'] += $line['errors'];
        }
        return $total;
    }
}
