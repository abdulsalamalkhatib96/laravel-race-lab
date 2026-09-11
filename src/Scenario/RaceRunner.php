<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use Illuminate\Contracts\Container\Container;
use RaceLab\LaravelRaceLab\Contracts\WorkerExecutor;
use RaceLab\LaravelRaceLab\Contracts\Coordinator;
use RaceLab\LaravelRaceLab\Coordination\CoordinatorManager;
use RaceLab\LaravelRaceLab\Support\RunStorage;
use RaceLab\LaravelRaceLab\Exceptions\ProductionGuardException;
use RaceLab\LaravelRaceLab\Exceptions\InvalidScenarioException;
use RaceLab\LaravelRaceLab\Exceptions\WorkerCrashedException;
use RaceLab\LaravelRaceLab\Exceptions\WorkerTimeoutException;
use Symfony\Component\Process\Process;

final class RaceRunner
{
    public function __construct(
        private CoordinatorManager $coordinators,
        private WorkerExecutor $executor,
        private RunStorage $storage,
        private Container $container,
    ) {
    }

    public function start(ExecutionPlan $plan): RunningRace
    {
        $this->assertSafeEnvironment();
        $coordinator = $this->coordinators->driver($plan->coordinator);
        $planPath = $this->storage->writePlan($plan);
        $coordinator->initialize($plan);

        try {
            $processes = $this->executor->start($plan, $planPath);
            $this->waitForWorkerReadiness($plan, $coordinator, $processes);
            $coordinator->releaseStart($plan->runId);
        } catch (\Throwable $e) {
            try { $coordinator->abort($plan->runId, 'Failed while starting race workers: '.$e->getMessage()); } catch (\Throwable) {}
            if (isset($processes)) $this->executor->stop($processes);
            throw $e;
        }

        return new RunningRace(
            plan: $plan,
            coordinator: $coordinator,
            executor: $this->executor,
            processes: $processes,
            storage: $this->storage,
        );
    }

    /** @param array<string,object> $processes */
    private function waitForWorkerReadiness(ExecutionPlan $plan, Coordinator $coordinator, array $processes): void
    {
        $deadline = microtime(true) + $plan->workerTimeoutSeconds;

        do {
            $snapshot = $coordinator->snapshot($plan->runId);
            $allReady = true;

            foreach ($plan->workerIds() as $workerId) {
                $workerState = $snapshot['workers'][$workerId] ?? null;
                $status = is_array($workerState) ? ($workerState['status'] ?? null) : $workerState;

                if (in_array($status, ['failed', 'crashed', 'timed_out', 'aborted'], true)) {
                    throw new WorkerCrashedException("Worker [{$workerId}] failed while booting before the start gate.");
                }

                $process = $processes[$workerId] ?? null;
                if ($status !== 'ready') {
                    $allReady = false;
                    if ($process instanceof Process && ! $process->isRunning() && $process->getExitCode() !== null) {
                        $stderr = trim($process->getErrorOutput());
                        throw new WorkerCrashedException(
                            "Worker [{$workerId}] exited during boot with code ".var_export($process->getExitCode(), true).($stderr !== '' ? ': '.$stderr : '.')
                        );
                    }
                }
            }

            if ($allReady) return;
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new WorkerTimeoutException('Timed out waiting for race workers to boot and reach the start gate.');
    }

    private function assertSafeEnvironment(): void
    {
        $config = $this->container->make('config');
        $environment = method_exists($this->container, 'environment') ? $this->container->environment() : null;
        if ($environment === 'production' && ! (bool) $config->get('race-lab.allow_production', false)) {
            throw new ProductionGuardException('Laravel Race Lab refuses to run in production.');
        }

        if ((bool) $config->get('race-lab.guard_parent_transactions', true) && $this->container->bound('db')) {
            foreach (array_keys((array) $config->get('database.connections', [])) as $connectionName) {
                try {
                    if ($this->container->make('db')->connection($connectionName)->transactionLevel() > 0) {
                        throw new InvalidScenarioException(
                            "Cannot start Race Lab while connection [{$connectionName}] is inside a parent transaction. Commit fixtures before spawning workers."
                        );
                    }
                } catch (InvalidScenarioException $e) {
                    throw $e;
                } catch (\Throwable) {
                }
            }
        }
    }
}
