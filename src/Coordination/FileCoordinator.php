<?php

namespace RaceLab\LaravelRaceLab\Coordination;

use RaceLab\LaravelRaceLab\Contracts\Coordinator;
use RaceLab\LaravelRaceLab\Exceptions\BarrierTimeoutException;
use RaceLab\LaravelRaceLab\Exceptions\CoordinatorException;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;
use RaceLab\LaravelRaceLab\Support\RunStorage;

final class FileCoordinator implements Coordinator
{
    public function __construct(
        private RunStorage $storage,
        private int $pollIntervalMicroseconds = 10_000,
    ) {
    }

    public function initialize(ExecutionPlan $plan): void
    {
        $this->storage->ensureRun($plan->runId);

        $barriers = [];
        foreach ($plan->barriers as $barrier) {
            $barriers[$barrier->id] = [
                'expected' => array_values($barrier->workers),
                'arrived' => [],
                'released' => [],
                'auto_release' => $barrier->autoRelease,
                'timeout_seconds' => $barrier->timeoutSeconds,
            ];
        }

        $workers = [];
        foreach ($plan->workerIds() as $workerId) {
            $workers[$workerId] = [
                'status' => 'pending',
                'ready_at' => null,
                'result' => null,
            ];
        }

        $state = [
            'schema_version' => 1,
            'run_id' => $plan->runId,
            'status' => 'running',
            'start_released' => false,
            'abort_reason' => null,
            'workers' => $workers,
            'barriers' => $barriers,
            'created_at' => microtime(true),
            'updated_at' => microtime(true),
        ];

        $this->replaceState($plan->runId, $state);
        $trace = $this->storage->tracePath($plan->runId);
        if (! file_exists($trace)) {
            @touch($trace);
            @chmod($trace, 0600);
        }
        $this->record($plan->runId, 'run.initialized', null, [
            'name' => $plan->name,
            'workers' => $plan->workerIds(),
            'coordinator' => 'file',
        ]);
    }

    public function markReady(string $runId, string $workerId): void
    {
        $this->mutate($runId, function (array &$state) use ($workerId): void {
            $this->assertWorkerExists($state, $workerId);
            $state['workers'][$workerId]['status'] = 'ready';
            $state['workers'][$workerId]['ready_at'] = microtime(true);
        });

        $this->record($runId, 'worker.ready', $workerId);
    }

    public function waitUntilAllReady(string $runId, array $workerIds, float $timeoutSeconds): void
    {
        $this->poll(
            $runId,
            $timeoutSeconds,
            function (array $state) use ($workerIds): bool {
                foreach ($workerIds as $workerId) {
                    $status = $state['workers'][$workerId]['status'] ?? null;
                    if (in_array($status, ['failed', 'crashed', 'timed_out', 'aborted'], true)) {
                        throw new CoordinatorException("Worker [{$workerId}] failed before the start gate was released.");
                    }
                    if ($status !== 'ready') return false;
                }
                return true;
            },
            "Timed out waiting for all workers to become ready.",
        );
    }

    public function releaseStart(string $runId): void
    {
        $this->mutate($runId, fn (array &$state) => $state['start_released'] = true);
        $this->record($runId, 'run.start_released');
    }

    public function waitForStart(string $runId, string $workerId, float $timeoutSeconds): void
    {
        $this->poll(
            $runId,
            $timeoutSeconds,
            static fn (array $state): bool => (bool) ($state['start_released'] ?? false),
            "Worker [{$workerId}] timed out waiting for the start gate.",
        );
    }

    public function arrive(string $runId, string $barrierId, string $workerId, array $context = []): void
    {
        $autoReleased = false;
        $this->mutate($runId, function (array &$state) use ($barrierId, $workerId, $context, &$autoReleased): void {
            $barrier = &$this->barrierRef($state, $barrierId);

            if (! in_array($workerId, $barrier['expected'], true)) {
                throw new CoordinatorException("Worker [{$workerId}] is not expected at barrier [{$barrierId}].");
            }

            $barrier['arrived'][$workerId] = [
                'at' => microtime(true),
                'context' => $context,
            ];

            if ($barrier['auto_release'] && $this->containsAll(array_keys($barrier['arrived']), $barrier['expected'])) {
                $barrier['released'] = array_values(array_unique(array_merge($barrier['released'], $barrier['expected'])));
                $autoReleased = true;
            }
        });

        $this->record($runId, 'checkpoint.arrived', $workerId, ['barrier' => $barrierId] + $context);
        if ($autoReleased) {
            $this->record($runId, 'barrier.auto_released', null, ['barrier' => $barrierId]);
        }
    }

    public function waitForRelease(string $runId, string $barrierId, string $workerId, float $timeoutSeconds): void
    {
        $this->poll(
            $runId,
            $timeoutSeconds,
            static fn (array $state): bool => in_array(
                $workerId,
                $state['barriers'][$barrierId]['released'] ?? [],
                true,
            ),
            "Worker [{$workerId}] timed out at barrier [{$barrierId}].",
            BarrierTimeoutException::class,
        );

        $this->record($runId, 'checkpoint.released', $workerId, ['barrier' => $barrierId]);
    }

    public function waitForBarrier(string $runId, string $barrierId, ?array $workerIds, float $timeoutSeconds): void
    {
        try {
            $this->poll(
                $runId,
                $timeoutSeconds,
                function (array $state) use ($barrierId, $workerIds): bool {
                    $barrier = $state['barriers'][$barrierId] ?? null;
                    if ($barrier === null) throw new CoordinatorException("Unknown barrier [{$barrierId}].");

                    $expected = $workerIds ?? $barrier['expected'];
                    return $this->containsAll(array_keys($barrier['arrived']), $expected);
                },
                "Timed out waiting for barrier [{$barrierId}].",
                BarrierTimeoutException::class,
            );
        } catch (BarrierTimeoutException $e) {
            $state = $this->readState($runId);
            $barrier = $state['barriers'][$barrierId] ?? ['expected' => [], 'arrived' => []];
            $expected = $workerIds ?? $barrier['expected'];
            $arrived = array_keys($barrier['arrived']);
            $missing = array_values(array_diff($expected, $arrived));
            $message = sprintf(
                'Barrier [%s] timed out. Expected: [%s]; arrived: [%s]; missing: [%s].',
                $barrierId,
                implode(', ', $expected),
                implode(', ', $arrived),
                implode(', ', $missing),
            );
            throw new BarrierTimeoutException($message, previous: $e);
        }
    }

    public function release(string $runId, string $barrierId, ?array $workerIds = null): void
    {
        $released = [];
        $this->mutate($runId, function (array &$state) use ($barrierId, $workerIds, &$released): void {
            $barrier = &$this->barrierRef($state, $barrierId);
            $released = $workerIds ?? $barrier['expected'];

            foreach ($released as $workerId) {
                if (! in_array($workerId, $barrier['expected'], true)) {
                    throw new CoordinatorException("Cannot release unexpected worker [{$workerId}] from barrier [{$barrierId}].");
                }
            }

            $barrier['released'] = array_values(array_unique(array_merge($barrier['released'], $released)));
        });

        $this->record($runId, 'barrier.released', null, ['barrier' => $barrierId, 'workers' => $released]);
    }

    public function markWorkerResult(string $runId, string $workerId, array $result): void
    {
        $this->mutate($runId, function (array &$state) use ($workerId, $result): void {
            $this->assertWorkerExists($state, $workerId);
            $state['workers'][$workerId]['status'] = $result['status'] ?? 'failed';
            $state['workers'][$workerId]['result'] = $result;
        });
    }

    public function workerResults(string $runId): array
    {
        $state = $this->readState($runId);
        $results = [];

        foreach ($state['workers'] ?? [] as $workerId => $worker) {
            if (is_array($worker['result'] ?? null)) {
                $results[$workerId] = $worker['result'];
            }
        }

        return $results;
    }

    public function record(string $runId, string $type, ?string $workerId = null, array $data = []): void
    {
        $this->storage->ensureRun($runId);
        $path = $this->storage->tracePath($runId);
        $handle = @fopen($path, 'ab');
        if ($handle === false) throw new CoordinatorException("Unable to open trace file [{$path}].");

        try {
            if (! flock($handle, LOCK_EX)) throw new CoordinatorException('Unable to lock Race Lab trace file.');
            $line = json_encode([
                'timestamp' => microtime(true),
                'type' => $type,
                'worker' => $workerId,
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public function events(string $runId): array
    {
        $path = $this->storage->tracePath($runId);
        if (! is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $events = [];
        foreach ($lines as $line) {
            try {
                $events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Ignore a partially-written final line after a hard process crash.
            }
        }

        usort($events, static fn (array $a, array $b): int => ($a['timestamp'] <=> $b['timestamp']));
        return $events;
    }

    public function snapshot(string $runId): array
    {
        return $this->readState($runId);
    }

    public function complete(string $runId, bool $success): void
    {
        $this->mutate($runId, function (array &$state) use ($success): void {
            $state['status'] = $success ? 'completed' : 'failed';
        });
    }

    public function abort(string $runId, string $reason): void
    {
        $this->mutate($runId, function (array &$state) use ($reason): void {
            $state['status'] = 'aborted';
            $state['abort_reason'] = $reason;
            $state['start_released'] = true;
            foreach ($state['barriers'] as &$barrier) {
                $barrier['released'] = array_values(array_unique(array_merge($barrier['released'], $barrier['expected'])));
            }
            unset($barrier);
        });

        $this->record($runId, 'run.aborted', null, ['reason' => $reason]);
    }

    public function isAborted(string $runId): bool
    {
        return ($this->readState($runId)['status'] ?? null) === 'aborted';
    }

    public function cleanup(string $runId): void
    {
        $this->storage->deleteRun($runId);
    }

    private function poll(
        string $runId,
        float $timeoutSeconds,
        callable $condition,
        string $timeoutMessage,
        string $exceptionClass = CoordinatorException::class,
    ): void {
        $deadline = microtime(true) + max(0.001, $timeoutSeconds);

        do {
            $state = $this->readState($runId);
            if (($state['status'] ?? null) === 'aborted') {
                throw new CoordinatorException('Race Lab run aborted: '.($state['abort_reason'] ?? 'unknown reason'));
            }

            if ($condition($state)) return;
            usleep($this->pollIntervalMicroseconds);
        } while (microtime(true) < $deadline);

        throw new $exceptionClass($timeoutMessage);
    }

    private function readState(string $runId): array
    {
        $this->storage->ensureRun($runId);
        $lock = $this->openLock($runId);

        try {
            if (! flock($lock, LOCK_SH)) throw new CoordinatorException('Unable to obtain shared coordination lock.');
            $path = $this->storage->statePath($runId);
            if (! is_file($path)) throw new CoordinatorException("Race Lab state not found [{$path}].");
            $raw = file_get_contents($path);
            if ($raw === false) throw new CoordinatorException("Unable to read Race Lab state [{$path}].");
            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            flock($lock, LOCK_UN);
            return $state;
        } finally {
            fclose($lock);
        }
    }

    private function replaceState(string $runId, array $state): void
    {
        $this->storage->ensureRun($runId);
        $lock = $this->openLock($runId);

        try {
            if (! flock($lock, LOCK_EX)) throw new CoordinatorException('Unable to obtain exclusive coordination lock.');
            $this->writeState($runId, $state);
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    private function mutate(string $runId, callable $mutator): void
    {
        $this->storage->ensureRun($runId);
        $lock = $this->openLock($runId);

        try {
            if (! flock($lock, LOCK_EX)) throw new CoordinatorException('Unable to obtain exclusive coordination lock.');
            $path = $this->storage->statePath($runId);
            if (! is_file($path)) throw new CoordinatorException("Race Lab state not found [{$path}].");
            $raw = file_get_contents($path);
            if ($raw === false) throw new CoordinatorException("Unable to read Race Lab state [{$path}].");
            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $mutator($state);
            $state['updated_at'] = microtime(true);
            $this->writeState($runId, $state);
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    private function writeState(string $runId, array $state): void
    {
        $path = $this->storage->statePath($runId);
        $tmp = $path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(2));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false) throw new CoordinatorException("Unable to write Race Lab state [{$tmp}].");
        @chmod($tmp, 0600);
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new CoordinatorException("Unable to atomically replace Race Lab state [{$path}].");
        }
    }

    /** @return resource */
    private function openLock(string $runId)
    {
        $path = $this->storage->lockPath($runId);
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new CoordinatorException("Unable to open coordination lock [{$path}].");
        @chmod($path, 0600);
        return $handle;
    }

    private function &barrierRef(array &$state, string $barrierId): array
    {
        if (! isset($state['barriers'][$barrierId])) {
            throw new CoordinatorException("Unknown barrier [{$barrierId}].");
        }

        return $state['barriers'][$barrierId];
    }

    private function assertWorkerExists(array $state, string $workerId): void
    {
        if (! isset($state['workers'][$workerId])) {
            throw new CoordinatorException("Unknown worker [{$workerId}].");
        }
    }

    private function containsAll(array $actual, array $expected): bool
    {
        return count(array_diff($expected, $actual)) === 0;
    }
}
