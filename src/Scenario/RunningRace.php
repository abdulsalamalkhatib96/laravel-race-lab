<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use RaceLab\LaravelRaceLab\Contracts\Coordinator;
use RaceLab\LaravelRaceLab\Contracts\WorkerExecutor;
use RaceLab\LaravelRaceLab\Enums\FailureType;
use RaceLab\LaravelRaceLab\Enums\WorkerStatus;
use RaceLab\LaravelRaceLab\Results\RaceResult;
use RaceLab\LaravelRaceLab\Results\ThrowableSnapshot;
use RaceLab\LaravelRaceLab\Results\WorkerResult;
use RaceLab\LaravelRaceLab\Support\RunStorage;
use RaceLab\LaravelRaceLab\Support\SignalGuard;
use Symfony\Component\Process\Process;

final class RunningRace
{
    private float $startedAt;
    private ?RaceResult $joinedResult = null;
    private bool $aborted = false;
    private SignalGuard $signalGuard;

    /** @param array<string,object> $processes */
    public function __construct(
        public readonly ExecutionPlan $plan,
        private Coordinator $coordinator,
        private WorkerExecutor $executor,
        private array $processes,
        private RunStorage $storage,
    ) {
        $this->startedAt = microtime(true);
        $this->signalGuard = new SignalGuard();
        $this->signalGuard->install(fn (int $signal) => $this->abort('Received signal '.$signal));
    }

    public function waitFor(string $barrierId, ?array $workerIds = null, ?float $timeoutSeconds = null): self
    {
        try {
            $this->coordinator->waitForBarrier(
                $this->plan->runId,
                $barrierId,
                $workerIds,
                $timeoutSeconds ?? $this->plan->barrierTimeoutSeconds,
            );
        } catch (\Throwable $e) {
            $this->abort('waitFor failed: '.$e->getMessage());
            throw $e;
        }
        return $this;
    }

    public function release(string $barrierId, ?array $workerIds = null): self
    {
        $this->coordinator->release($this->plan->runId, $barrierId, $workerIds);
        return $this;
    }

    public function releaseAll(): self
    {
        foreach ($this->plan->barriers as $barrier) {
            $this->coordinator->release($this->plan->runId, $barrier->id);
        }
        return $this;
    }

    public function abort(string $reason = 'Aborted by caller'): void
    {
        if ($this->joinedResult !== null || $this->aborted) return;
        $this->aborted = true;
        try { $this->coordinator->abort($this->plan->runId, $reason); } catch (\Throwable) {}
        $this->executor->stop($this->processes);
        $this->signalGuard->restore();
    }

    public function join(): RaceResult
    {
        if ($this->joinedResult !== null) return $this->joinedResult;

        $deadline = $this->startedAt + $this->plan->scenarioTimeoutSeconds;

        while (true) {
            $rawResults = $this->coordinator->workerResults($this->plan->runId);
            $allStopped = true;

            foreach ($this->processes as $workerId => $process) {
                if ($process instanceof Process && $process->isRunning()) {
                    $allStopped = false;
                    continue;
                }

                if ($process instanceof Process && ! isset($rawResults[$workerId]) && $process->getExitCode() !== null) {
                    $crash = $this->crashedPayload($workerId, $process);
                    $this->coordinator->markWorkerResult($this->plan->runId, $workerId, $crash);
                    $this->coordinator->record($this->plan->runId, 'worker.crashed', $workerId, [
                        'exit_code' => $process->getExitCode(),
                        'stderr' => $this->truncate($process->getErrorOutput()),
                    ]);
                    $rawResults[$workerId] = $crash;
                }
            }

            if (count($rawResults) >= count($this->plan->workers) && $allStopped) break;

            if (microtime(true) >= $deadline) {
                $this->coordinator->abort($this->plan->runId, 'Scenario timeout exceeded.');
                $this->executor->stop($this->processes);

                $rawResults = $this->coordinator->workerResults($this->plan->runId);
                foreach ($this->plan->workerIds() as $workerId) {
                    if (! isset($rawResults[$workerId])) {
                        $payload = $this->timedOutPayload($workerId);
                        $this->coordinator->markWorkerResult($this->plan->runId, $workerId, $payload);
                    }
                }
                break;
            }

            usleep(10_000);
        }

        $rawResults = $this->coordinator->workerResults($this->plan->runId);
        $workers = [];

        foreach ($this->plan->workerIds() as $workerId) {
            $process = $this->processes[$workerId] ?? null;
            $payload = $rawResults[$workerId] ?? $this->missingPayload($workerId);
            $workers[$workerId] = WorkerResult::fromArray(
                $payload,
                $process instanceof Process ? $process->getExitCode() : null,
                $process instanceof Process ? $process->getOutput() : '',
                $process instanceof Process ? $process->getErrorOutput() : '',
            );
        }

        $success = count(array_filter($workers, static fn (WorkerResult $result) => $result->succeeded())) === count($workers);
        if (! $this->coordinator->isAborted($this->plan->runId)) {
            $this->coordinator->complete($this->plan->runId, $success);
        }
        $this->coordinator->record($this->plan->runId, 'run.completed', null, [
            'success' => $success,
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000, 3),
        ]);

        $events = $this->coordinator->events($this->plan->runId);
        $snapshot = $this->coordinator->snapshot($this->plan->runId);
        $runPath = $this->storage->runPath($this->plan->runId);

        $this->joinedResult = new RaceResult($this->plan, $workers, $events, $snapshot, $runPath);

        if (! $success || $this->plan->persistTrace) {
            try { $this->storage->writeResult($this->plan->runId, $this->joinedResult->toArray()); } catch (\Throwable) {}
        }

        if ($success && ! $this->plan->persistTrace) {
            try { $this->coordinator->cleanup($this->plan->runId); } catch (\Throwable) {}
            $this->storage->deleteRun($this->plan->runId);
        }

        $this->signalGuard->restore();
        return $this->joinedResult;
    }

    public function __destruct()
    {
        if ($this->joinedResult === null) {
            $running = false;
            foreach ($this->processes as $process) {
                if ($process instanceof Process && $process->isRunning()) { $running = true; break; }
            }
            if ($running) $this->abort('RunningRace object destroyed before join().');
        }
        $this->signalGuard->restore();
    }

    private function crashedPayload(string $workerId, Process $process): array
    {
        $message = 'Worker process exited unexpectedly with code '.var_export($process->getExitCode(), true).'.';
        $stderr = trim($process->getErrorOutput());
        if ($stderr !== '') $message .= ' '.$this->truncate($stderr);

        return [
            'worker_id' => $workerId,
            'status' => WorkerStatus::Crashed->value,
            'return_value' => null,
            'exception' => (new ThrowableSnapshot(\RuntimeException::class, $message, $process->getExitCode() ?? -1, '', 0, ''))->toArray(),
            'failure_type' => FailureType::WorkerCrash->value,
            'started_at' => $this->startedAt,
            'finished_at' => microtime(true),
        ];
    }

    private function timedOutPayload(string $workerId): array
    {
        return [
            'worker_id' => $workerId,
            'status' => WorkerStatus::TimedOut->value,
            'return_value' => null,
            'exception' => (new ThrowableSnapshot(\RuntimeException::class, 'Scenario timeout exceeded.', 0, '', 0, ''))->toArray(),
            'failure_type' => FailureType::Timeout->value,
            'started_at' => $this->startedAt,
            'finished_at' => microtime(true),
        ];
    }

    private function missingPayload(string $workerId): array
    {
        return [
            'worker_id' => $workerId,
            'status' => WorkerStatus::Crashed->value,
            'return_value' => null,
            'exception' => (new ThrowableSnapshot(\RuntimeException::class, 'Worker exited without publishing a result.', -1, '', 0, ''))->toArray(),
            'failure_type' => FailureType::WorkerCrash->value,
            'started_at' => $this->startedAt,
            'finished_at' => microtime(true),
        ];
    }

    private function truncate(string $value, int $max = 2000): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max).'…';
    }
}
