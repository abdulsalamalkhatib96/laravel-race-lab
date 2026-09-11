<?php

namespace RaceLab\LaravelRaceLab\Process;

use RaceLab\LaravelRaceLab\Contracts\WorkerExecutor;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;
use Symfony\Component\Process\Process;

final class SubprocessExecutor implements WorkerExecutor
{
    public function __construct(
        private string $basePath,
        private ?string $phpBinary = null,
    ) {
        $this->basePath = rtrim($this->basePath, DIRECTORY_SEPARATOR);
        $this->phpBinary ??= PHP_BINARY;
    }

    public function start(ExecutionPlan $plan, string $planPath): array
    {
        $processes = [];

        foreach ($plan->workerIds() as $workerId) {
            $process = new Process(
                command: [
                    $this->phpBinary,
                    $this->basePath.DIRECTORY_SEPARATOR.'artisan',
                    'race-lab:worker',
                    '--plan='.$planPath,
                    '--worker='.$workerId,
                ],
                cwd: $this->basePath,
                env: [
                    'RACE_LAB_ACTIVE' => '1',
                    'RACE_LAB_RUN_ID' => $plan->runId,
                    'RACE_LAB_WORKER_ID' => $workerId,
                ],
            );

            $process->setTimeout(null);
            $process->setIdleTimeout(null);
            $process->start();
            $processes[$workerId] = $process;
        }

        return $processes;
    }

    public function stop(array $processes, float $timeoutSeconds = 1.0): void
    {
        foreach ($processes as $process) {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop($timeoutSeconds);
            }
        }
    }
}
