<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use Closure;
use RaceLab\LaravelRaceLab\Contracts\ActionSerializer;
use RaceLab\LaravelRaceLab\Enums\DeterminismLevel;
use RaceLab\LaravelRaceLab\Exceptions\InvalidScenarioException;
use RaceLab\LaravelRaceLab\Support\RunId;

final class ScenarioCompiler
{
    public function __construct(private ActionSerializer $serializer) {}

    public function compile(string $name, array $actions, array $barriers, array $settings): ExecutionPlan
    {
        if (count($actions) < 2) throw new InvalidScenarioException('A race scenario requires at least two workers.');

        $workerIds = array_keys($actions);
        if (count($workerIds) !== count(array_unique($workerIds))) {
            throw new InvalidScenarioException('Race worker IDs must be unique.');
        }

        $workers = [];
        foreach ($actions as $id => $definition) {
            $workers[] = new WorkerDefinition(
                id: (string) $id,
                actionPayload: $this->serializer->serialize($definition['action']),
                metadata: $definition['metadata'] ?? [],
            );
        }

        $barrierIds = [];
        $compiledBarriers = [];
        foreach ($barriers as $barrier) {
            if (isset($barrierIds[$barrier->id])) throw new InvalidScenarioException("Duplicate barrier ID [{$barrier->id}].");
            $barrierIds[$barrier->id] = true;

            $expected = $barrier->workers === [] ? $workerIds : $barrier->workers;
            foreach ($expected as $workerId) {
                if (! isset($actions[$workerId])) {
                    throw new InvalidScenarioException("Barrier [{$barrier->id}] references unknown worker [{$workerId}].");
                }
            }

            $compiledBarriers[] = new BarrierDefinition(
                id: $barrier->id,
                checkpoint: $barrier->checkpoint,
                workers: array_values($expected),
                autoRelease: $barrier->autoRelease,
                timeoutSeconds: $barrier->timeoutSeconds,
            );
        }

        $determinism = $compiledBarriers === [] ? DeterminismLevel::SchedulingDependent : DeterminismLevel::Deterministic;

        return new ExecutionPlan(
            runId: RunId::make(),
            name: $name,
            workers: $workers,
            barriers: $compiledBarriers,
            coordinator: (string) ($settings['coordinator'] ?? 'file'),
            scenarioTimeoutSeconds: (float) ($settings['scenario_timeout'] ?? 10),
            workerTimeoutSeconds: (float) ($settings['worker_timeout'] ?? 8),
            barrierTimeoutSeconds: (float) ($settings['barrier_timeout'] ?? 5),
            persistTrace: (bool) ($settings['persist_trace'] ?? true),
            determinism: $determinism,
            metadata: (array) ($settings['metadata'] ?? []),
        );
    }
}
