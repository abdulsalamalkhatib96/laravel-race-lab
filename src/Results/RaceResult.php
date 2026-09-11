<?php

namespace RaceLab\LaravelRaceLab\Results;

use Illuminate\Container\Container;
use RaceLab\LaravelRaceLab\Enums\FailureType;
use RaceLab\LaravelRaceLab\Enums\WorkerStatus;
use RaceLab\LaravelRaceLab\Exceptions\RaceAssertionFailed;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;

final class RaceResult
{
    /** @param array<string,WorkerResult> $workers @param list<array<string,mixed>> $events */
    public function __construct(
        public readonly ExecutionPlan $plan,
        private array $workers,
        private array $events,
        private array $snapshot,
        public readonly string $runPath,
    ) {
    }

    /** @return array<string,WorkerResult> */
    public function workers(): array { return $this->workers; }

    public function worker(string $id): WorkerResult
    {
        if (! isset($this->workers[$id])) $this->fail("Unknown race worker [{$id}].");
        return $this->workers[$id];
    }

    /** @return list<array<string,mixed>> */
    public function timeline(): array { return $this->events; }

    public function snapshot(): array { return $this->snapshot; }

    public function succeededCount(): int
    {
        return count(array_filter($this->workers, static fn (WorkerResult $result) => $result->succeeded()));
    }

    public function failedCount(): int { return count($this->workers) - $this->succeededCount(); }

    public function allSucceeded(): bool { return $this->succeededCount() === count($this->workers); }

    public function assertAllSucceeded(): self
    {
        if (! $this->allSucceeded()) {
            $failed = array_map(static fn (WorkerResult $r) => $r->workerId.':'.$r->status->value, array_filter($this->workers, static fn (WorkerResult $r) => ! $r->succeeded()));
            $this->fail('Expected all race workers to succeed. Failed: '.implode(', ', $failed));
        }
        return $this;
    }

    public function assertExactlyOneSucceeded(): self
    {
        if ($this->succeededCount() !== 1) $this->fail("Expected exactly one worker to succeed; actual {$this->succeededCount()}.");
        return $this;
    }

    public function assertExactlyOneFailed(): self
    {
        if ($this->failedCount() !== 1) $this->fail("Expected exactly one worker to fail; actual {$this->failedCount()}.");
        return $this;
    }

    public function assertWorkerSucceeded(string $workerId): self
    {
        if (! $this->worker($workerId)->succeeded()) $this->fail("Expected worker [{$workerId}] to succeed.");
        return $this;
    }

    public function assertWorkerFailed(string $workerId): self
    {
        if ($this->worker($workerId)->succeeded()) $this->fail("Expected worker [{$workerId}] to fail.");
        return $this;
    }

    public function assertNoTimeouts(): self
    {
        foreach ($this->workers as $worker) {
            if ($worker->status === WorkerStatus::TimedOut || $worker->failureType === FailureType::Timeout) {
                $this->fail("Worker [{$worker->workerId}] timed out.");
            }
        }
        return $this;
    }

    public function assertNoDeadlocks(): self
    {
        foreach ($this->workers as $worker) {
            if ($worker->failureType === FailureType::Deadlock) $this->fail("Worker [{$worker->workerId}] encountered a database deadlock.");
        }
        return $this;
    }

    public function assertExceptionCount(string $throwableClass, int $expected): self
    {
        $actual = count(array_filter($this->workers, static fn (WorkerResult $r) => $r->exception !== null && is_a($r->exception->class, $throwableClass, true)));
        if ($actual !== $expected) $this->fail("Expected {$expected} [{$throwableClass}] exceptions; actual {$actual}.");
        return $this;
    }

    public function assertDatabaseCount(string $table, int $expected): self
    {
        $actual = (int) $this->db()->table($table)->count();
        if ($actual !== $expected) $this->fail("Expected [{$table}] row count {$expected}; actual {$actual}.");
        return $this;
    }

    public function assertCount(string $table, int $expected): self
    {
        return $this->assertDatabaseCount($table, $expected);
    }

    public function assertDatabaseHas(string $table, array $where): self
    {
        if (! $this->db()->table($table)->where($where)->exists()) {
            $this->fail("Expected database table [{$table}] to contain matching row: ".json_encode($where, JSON_UNESCAPED_SLASHES));
        }
        return $this;
    }

    public function assertInvariant(callable $invariant, string $message = 'Race invariant failed.'): self
    {
        $result = $invariant($this);
        if ($result === false) $this->fail($message);
        return $this;
    }

    public function report(): string
    {
        return (new TimelineReporter())->render($this);
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->plan->runId,
            'name' => $this->plan->name,
            'determinism' => $this->plan->determinism->value,
            'workers' => array_map(static fn (WorkerResult $r) => $r->toArray(), $this->workers),
            'events' => $this->events,
            'snapshot' => $this->snapshot,
            'run_path' => $this->runPath,
        ];
    }

    private function db(): object
    {
        $container = Container::getInstance();
        if (! $container || ! $container->bound('db')) $this->fail('Laravel database manager is not available for database assertion.');
        return $container->make('db');
    }

    private function fail(string $message): never
    {
        if (class_exists(\PHPUnit\Framework\AssertionFailedError::class)) {
            throw new \PHPUnit\Framework\AssertionFailedError($message);
        }
        throw new RaceAssertionFailed($message);
    }
}
