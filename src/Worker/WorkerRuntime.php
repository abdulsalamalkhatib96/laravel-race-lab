<?php

namespace RaceLab\LaravelRaceLab\Worker;

use RaceLab\LaravelRaceLab\Contracts\ActionSerializer;
use RaceLab\LaravelRaceLab\Coordination\CoordinatorManager;
use RaceLab\LaravelRaceLab\Database\QueryInstrumentation;
use RaceLab\LaravelRaceLab\Database\TransactionInstrumentation;
use RaceLab\LaravelRaceLab\Enums\WorkerStatus;
use RaceLab\LaravelRaceLab\Exceptions\InvalidScenarioException;
use RaceLab\LaravelRaceLab\Results\FailureClassifier;
use RaceLab\LaravelRaceLab\Results\ThrowableSnapshot;
use RaceLab\LaravelRaceLab\Runtime\RuntimeContext;
use RaceLab\LaravelRaceLab\Support\RunStorage;

final class WorkerRuntime
{
    public function __construct(
        private RunStorage $storage,
        private CoordinatorManager $coordinators,
        private ActionSerializer $serializer,
        private RuntimeContext $runtime,
        private QueryInstrumentation $queries,
        private TransactionInstrumentation $transactions,
        private FailureClassifier $classifier,
    ) {
    }

    public function run(string $planPath, string $workerId): int
    {
        $plan = $this->storage->readPlan($planPath);
        $definition = $plan->worker($workerId);
        if ($definition === null) {
            throw new InvalidScenarioException("Worker [{$workerId}] does not exist in execution plan [{$plan->runId}].");
        }

        $coordinator = $this->coordinators->driver($plan->coordinator);
        $this->runtime->activate($plan, $workerId, $coordinator);
        $startedAt = microtime(true);

        try {
            $this->queries->install();
            $this->transactions->install();
            $coordinator->markReady($plan->runId, $workerId);
            $coordinator->waitForStart($plan->runId, $workerId, $plan->workerTimeoutSeconds);
            $startedAt = microtime(true);
            $coordinator->record($plan->runId, 'worker.started', $workerId);

            $action = $this->serializer->unserialize($definition->actionPayload);
            $reflection = new \ReflectionFunction($action);
            if ($reflection->getNumberOfRequiredParameters() > 1) {
                throw new InvalidScenarioException('Race worker closures may accept zero parameters or one WorkerContext parameter.');
            }

            $context = new WorkerContext($this->runtime);
            $returnValue = $reflection->getNumberOfParameters() >= 1 ? $action($context) : $action();
            $finishedAt = microtime(true);

            $result = [
                'worker_id' => $workerId,
                'status' => WorkerStatus::Succeeded->value,
                'return_value' => $this->normalizeReturnValue($returnValue),
                'exception' => null,
                'failure_type' => null,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ];

            $coordinator->markWorkerResult($plan->runId, $workerId, $result);
            $coordinator->record($plan->runId, 'worker.succeeded', $workerId, [
                'duration_ms' => round(($finishedAt - $startedAt) * 1000, 3),
            ]);

            return 0;
        } catch (\Throwable $e) {
            $finishedAt = microtime(true);
            $failureType = $this->classifier->classify($e);
            $result = [
                'worker_id' => $workerId,
                'status' => WorkerStatus::Failed->value,
                'return_value' => null,
                'exception' => ThrowableSnapshot::fromThrowable($e)->toArray(),
                'failure_type' => $failureType->value,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ];

            try {
                $coordinator->markWorkerResult($plan->runId, $workerId, $result);
                $coordinator->record($plan->runId, 'worker.failed', $workerId, [
                    'failure_type' => $failureType->value,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Parent process will classify an unreported worker as crashed.
            }

            // Application failures are test outcomes, not worker-process crashes.
            return 0;
        } finally {
            $this->runtime->deactivate();
        }
    }

    private function normalizeReturnValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) return $value;
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) $normalized[$key] = $this->normalizeReturnValue($item);
            return $normalized;
        }
        if ($value instanceof \JsonSerializable) return $this->normalizeReturnValue($value->jsonSerialize());
        if ($value instanceof \Stringable) return (string) $value;
        return ['__type' => get_debug_type($value)];
    }
}
