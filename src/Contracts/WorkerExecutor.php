<?php

namespace RaceLab\LaravelRaceLab\Contracts;

use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;

interface WorkerExecutor
{
    /** @return array<string,object> */
    public function start(ExecutionPlan $plan, string $planPath): array;

    /** @param array<string,object> $processes */
    public function stop(array $processes, float $timeoutSeconds = 1.0): void;
}
