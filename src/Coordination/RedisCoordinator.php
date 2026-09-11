<?php

namespace RaceLab\LaravelRaceLab\Coordination;

use RaceLab\LaravelRaceLab\Contracts\Coordinator;
use RaceLab\LaravelRaceLab\Exceptions\BarrierTimeoutException;
use RaceLab\LaravelRaceLab\Exceptions\CoordinatorException;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;

final class RedisCoordinator implements Coordinator
{
    public function __construct(
        private object $redisManager,
        private string $connectionName = 'default',
        private string $prefix = 'race-lab:',
        private int $pollIntervalMicroseconds = 10_000,
        private int $ttlSeconds = 600,
    ) {
        $this->prefix = rtrim($this->prefix, ':').':';
    }

    public function initialize(ExecutionPlan $plan): void
    {
        $redis = $this->redis();
        $meta = $this->key($plan->runId, 'meta');
        $workers = $this->key($plan->runId, 'workers');
        $barrierIds = [];

        $redis->hset($meta, 'status', 'running');
        $redis->hset($meta, 'start_released', '0');
        $redis->hset($meta, 'name', $plan->name);
        $redis->hset($meta, 'created_at', (string) microtime(true));

        foreach ($plan->workerIds() as $workerId) {
            $redis->hset($workers, $workerId, 'pending');
        }

        foreach ($plan->barriers as $barrier) {
            $barrierIds[] = $barrier->id;
            $redis->set($this->barrierKey($plan->runId, $barrier->id, 'expected'), json_encode($barrier->workers, JSON_THROW_ON_ERROR));
            $redis->set($this->barrierKey($plan->runId, $barrier->id, 'auto'), $barrier->autoRelease ? '1' : '0');
            $redis->del(
                $this->barrierKey($plan->runId, $barrier->id, 'arrived'),
                $this->barrierKey($plan->runId, $barrier->id, 'released'),
            );
        }

        $redis->set($this->key($plan->runId, 'barriers'), json_encode($barrierIds, JSON_THROW_ON_ERROR));
        $this->touchKeys($plan->runId, $barrierIds);
        $this->record($plan->runId, 'run.initialized', null, [
            'name' => $plan->name,
            'workers' => $plan->workerIds(),
            'coordinator' => 'redis',
        ]);
    }

    public function markReady(string $runId, string $workerId): void
    {
        $this->assertWorker($runId, $workerId);
        $this->redis()->hset($this->key($runId, 'workers'), $workerId, 'ready');
        $this->expire($this->key($runId, 'workers'));
        $this->record($runId, 'worker.ready', $workerId);
    }

    public function waitUntilAllReady(string $runId, array $workerIds, float $timeoutSeconds): void
    {
        $this->poll($runId, $timeoutSeconds, function () use ($runId, $workerIds): bool {
            $statuses = $this->normalizeHash($this->redis()->hgetall($this->key($runId, 'workers')));
            foreach ($workerIds as $workerId) {
                $status = $statuses[$workerId] ?? null;
                if (in_array($status, ['failed', 'crashed', 'timed_out', 'aborted'], true)) {
                    throw new CoordinatorException("Worker [{$workerId}] failed before the start gate was released.");
                }
                if ($status !== 'ready') return false;
            }
            return true;
        }, 'Timed out waiting for all workers to become ready.');
    }

    public function releaseStart(string $runId): void
    {
        $this->redis()->hset($this->key($runId, 'meta'), 'start_released', '1');
        $this->expire($this->key($runId, 'meta'));
        $this->record($runId, 'run.start_released');
    }

    public function waitForStart(string $runId, string $workerId, float $timeoutSeconds): void
    {
        $this->poll($runId, $timeoutSeconds, fn (): bool => (string) $this->redis()->hget($this->key($runId, 'meta'), 'start_released') === '1', "Worker [{$workerId}] timed out waiting for the start gate.");
    }

    public function arrive(string $runId, string $barrierId, string $workerId, array $context = []): void
    {
        $expected = $this->expected($runId, $barrierId);
        if (! in_array($workerId, $expected, true)) {
            throw new CoordinatorException("Worker [{$workerId}] is not expected at barrier [{$barrierId}].");
        }

        $redis = $this->redis();
        $arrivedKey = $this->barrierKey($runId, $barrierId, 'arrived');
        $redis->sadd($arrivedKey, $workerId);
        $this->expire($arrivedKey);
        $this->record($runId, 'checkpoint.arrived', $workerId, ['barrier' => $barrierId] + $context);

        $auto = (string) $redis->get($this->barrierKey($runId, $barrierId, 'auto')) === '1';
        if ($auto && (int) $redis->scard($arrivedKey) >= count($expected)) {
            $releasedKey = $this->barrierKey($runId, $barrierId, 'released');
            if ($expected !== []) $redis->sadd($releasedKey, ...$expected);
            $this->expire($releasedKey);
            $this->record($runId, 'barrier.auto_released', null, ['barrier' => $barrierId]);
        }
    }

    public function waitForRelease(string $runId, string $barrierId, string $workerId, float $timeoutSeconds): void
    {
        $this->poll(
            $runId,
            $timeoutSeconds,
            fn (): bool => in_array($workerId, $this->members($this->barrierKey($runId, $barrierId, 'released')), true),
            "Worker [{$workerId}] timed out at barrier [{$barrierId}].",
            BarrierTimeoutException::class,
        );

        $this->record($runId, 'checkpoint.released', $workerId, ['barrier' => $barrierId]);
    }

    public function waitForBarrier(string $runId, string $barrierId, ?array $workerIds, float $timeoutSeconds): void
    {
        $expected = $workerIds ?? $this->expected($runId, $barrierId);
        try {
            $this->poll(
                $runId,
                $timeoutSeconds,
                fn (): bool => count(array_diff($expected, $this->members($this->barrierKey($runId, $barrierId, 'arrived')))) === 0,
                "Timed out waiting for barrier [{$barrierId}].",
                BarrierTimeoutException::class,
            );
        } catch (BarrierTimeoutException $e) {
            $arrived = $this->members($this->barrierKey($runId, $barrierId, 'arrived'));
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
        $expected = $this->expected($runId, $barrierId);
        $workers = $workerIds ?? $expected;
        foreach ($workers as $workerId) {
            if (! in_array($workerId, $expected, true)) {
                throw new CoordinatorException("Cannot release unexpected worker [{$workerId}] from barrier [{$barrierId}].");
            }
        }

        $key = $this->barrierKey($runId, $barrierId, 'released');
        if ($workers !== []) $this->redis()->sadd($key, ...$workers);
        $this->expire($key);
        $this->record($runId, 'barrier.released', null, ['barrier' => $barrierId, 'workers' => array_values($workers)]);
    }

    public function markWorkerResult(string $runId, string $workerId, array $result): void
    {
        $redis = $this->redis();
        $redis->hset($this->key($runId, 'workers'), $workerId, (string) ($result['status'] ?? 'failed'));
        $redis->hset($this->key($runId, 'results'), $workerId, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->expire($this->key($runId, 'workers'));
        $this->expire($this->key($runId, 'results'));
    }

    public function workerResults(string $runId): array
    {
        $raw = $this->normalizeHash($this->redis()->hgetall($this->key($runId, 'results')));
        $results = [];
        foreach ($raw as $workerId => $payload) {
            $results[$workerId] = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    }

    public function record(string $runId, string $type, ?string $workerId = null, array $data = []): void
    {
        $key = $this->key($runId, 'events');
        $this->redis()->rpush($key, json_encode([
            'timestamp' => microtime(true),
            'type' => $type,
            'worker' => $workerId,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->expire($key);
    }

    public function events(string $runId): array
    {
        $raw = $this->redis()->lrange($this->key($runId, 'events'), 0, -1) ?: [];
        $events = array_map(static fn ($payload) => json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR), $raw);
        usort($events, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
        return $events;
    }

    public function snapshot(string $runId): array
    {
        $redis = $this->redis();
        $meta = $this->normalizeHash($redis->hgetall($this->key($runId, 'meta')));
        $workerStatuses = $this->normalizeHash($redis->hgetall($this->key($runId, 'workers')));
        $workers = [];
        $resultPayloads = $this->normalizeHash($redis->hgetall($this->key($runId, 'results')));
        foreach ($workerStatuses as $workerId => $status) {
            $workers[$workerId] = [
                'status' => $status,
                'result' => isset($resultPayloads[$workerId]) ? json_decode((string) $resultPayloads[$workerId], true) : null,
            ];
        }
        $barriers = [];

        foreach ($this->barrierIds($runId) as $barrierId) {
            $barriers[$barrierId] = [
                'expected' => $this->expected($runId, $barrierId),
                'arrived' => $this->members($this->barrierKey($runId, $barrierId, 'arrived')),
                'released' => $this->members($this->barrierKey($runId, $barrierId, 'released')),
                'auto_release' => (string) $redis->get($this->barrierKey($runId, $barrierId, 'auto')) === '1',
            ];
        }

        return [
            'run_id' => $runId,
            'status' => $meta['status'] ?? null,
            'start_released' => ($meta['start_released'] ?? '0') === '1',
            'abort_reason' => $meta['abort_reason'] ?? null,
            'workers' => $workers,
            'barriers' => $barriers,
        ];
    }

    public function complete(string $runId, bool $success): void
    {
        $meta = $this->key($runId, 'meta');
        $this->redis()->hset($meta, 'status', $success ? 'completed' : 'failed');
        $this->expire($meta);
    }

    public function abort(string $runId, string $reason): void
    {
        $redis = $this->redis();
        $meta = $this->key($runId, 'meta');
        $redis->hset($meta, 'status', 'aborted');
        $redis->hset($meta, 'abort_reason', $reason);
        $redis->hset($meta, 'start_released', '1');

        foreach ($this->barrierIds($runId) as $barrierId) {
            $expected = $this->expected($runId, $barrierId);
            if ($expected !== []) $redis->sadd($this->barrierKey($runId, $barrierId, 'released'), ...$expected);
        }

        $this->touchKeys($runId, $this->barrierIds($runId));
        $this->record($runId, 'run.aborted', null, ['reason' => $reason]);
    }

    public function isAborted(string $runId): bool
    {
        return (string) $this->redis()->hget($this->key($runId, 'meta'), 'status') === 'aborted';
    }

    public function cleanup(string $runId): void
    {
        $keys = [
            $this->key($runId, 'meta'),
            $this->key($runId, 'workers'),
            $this->key($runId, 'results'),
            $this->key($runId, 'events'),
            $this->key($runId, 'barriers'),
        ];

        foreach ($this->barrierIds($runId) as $barrierId) {
            foreach (['expected', 'auto', 'arrived', 'released'] as $suffix) {
                $keys[] = $this->barrierKey($runId, $barrierId, $suffix);
            }
        }

        if ($keys !== []) $this->redis()->del(...$keys);
    }

    private function poll(string $runId, float $timeoutSeconds, callable $condition, string $timeoutMessage, string $exceptionClass = CoordinatorException::class): void
    {
        $deadline = microtime(true) + max(0.001, $timeoutSeconds);
        do {
            if ($this->isAborted($runId)) {
                $reason = $this->redis()->hget($this->key($runId, 'meta'), 'abort_reason');
                throw new CoordinatorException('Race Lab run aborted: '.($reason ?: 'unknown reason'));
            }
            if ($condition()) return;
            usleep($this->pollIntervalMicroseconds);
        } while (microtime(true) < $deadline);

        throw new $exceptionClass($timeoutMessage);
    }

    private function redis(): object
    {
        return $this->redisManager->connection($this->connectionName);
    }

    private function expected(string $runId, string $barrierId): array
    {
        $raw = $this->redis()->get($this->barrierKey($runId, $barrierId, 'expected'));
        if ($raw === null || $raw === false) throw new CoordinatorException("Unknown barrier [{$barrierId}].");
        return array_values(json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR));
    }

    private function barrierIds(string $runId): array
    {
        $raw = $this->redis()->get($this->key($runId, 'barriers'));
        if ($raw === null || $raw === false) return [];
        return array_values(json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR));
    }

    private function members(string $key): array
    {
        return array_values(array_map('strval', $this->redis()->smembers($key) ?: []));
    }

    private function assertWorker(string $runId, string $workerId): void
    {
        $value = $this->redis()->hget($this->key($runId, 'workers'), $workerId);
        if ($value === null || $value === false) {
            throw new CoordinatorException("Unknown worker [{$workerId}].");
        }
    }

    private function key(string $runId, string $suffix): string
    {
        return $this->prefix.$runId.':'.$suffix;
    }

    private function barrierKey(string $runId, string $barrierId, string $suffix): string
    {
        return $this->key($runId, 'barrier:'.$barrierId.':'.$suffix);
    }

    private function expire(string $key): void
    {
        if ($this->ttlSeconds > 0) $this->redis()->expire($key, $this->ttlSeconds);
    }

    private function touchKeys(string $runId, array $barrierIds): void
    {
        foreach (['meta', 'workers', 'results', 'events', 'barriers'] as $suffix) {
            $this->expire($this->key($runId, $suffix));
        }
        foreach ($barrierIds as $barrierId) {
            foreach (['expected', 'auto', 'arrived', 'released'] as $suffix) {
                $this->expire($this->barrierKey($runId, $barrierId, $suffix));
            }
        }
    }

    private function normalizeHash(mixed $hash): array
    {
        if (! is_array($hash)) return [];
        // PhpRedis returns associative arrays; Predis may already do the same through Laravel.
        if (! array_is_list($hash)) return $hash;
        $normalized = [];
        for ($i = 0, $count = count($hash); $i + 1 < $count; $i += 2) {
            $normalized[(string) $hash[$i]] = $hash[$i + 1];
        }
        return $normalized;
    }
}
