<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use RaceLab\LaravelRaceLab\Enums\DeterminismLevel;

final readonly class ExecutionPlan
{
    /**
     * @param list<WorkerDefinition> $workers
     * @param list<BarrierDefinition> $barriers
     */
    public function __construct(
        public string $runId,
        public string $name,
        public array $workers,
        public array $barriers,
        public string $coordinator,
        public float $scenarioTimeoutSeconds,
        public float $workerTimeoutSeconds,
        public float $barrierTimeoutSeconds,
        public bool $persistTrace,
        public DeterminismLevel $determinism,
        public array $metadata = [],
    ) {
    }

    /** @return list<string> */
    public function workerIds(): array
    {
        return array_map(static fn (WorkerDefinition $worker) => $worker->id, $this->workers);
    }

    public function worker(string $id): ?WorkerDefinition
    {
        foreach ($this->workers as $worker) {
            if ($worker->id === $id) return $worker;
        }
        return null;
    }

    public function barrier(string $id): ?BarrierDefinition
    {
        foreach ($this->barriers as $barrier) {
            if ($barrier->id === $id) return $barrier;
        }
        return null;
    }

    public function withRunId(string $runId): self
    {
        return new self(
            runId: $runId,
            name: $this->name,
            workers: $this->workers,
            barriers: $this->barriers,
            coordinator: $this->coordinator,
            scenarioTimeoutSeconds: $this->scenarioTimeoutSeconds,
            workerTimeoutSeconds: $this->workerTimeoutSeconds,
            barrierTimeoutSeconds: $this->barrierTimeoutSeconds,
            persistTrace: $this->persistTrace,
            determinism: $this->determinism,
            metadata: $this->metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $this->runId,
            'name' => $this->name,
            'workers' => array_map(static fn (WorkerDefinition $worker) => $worker->toArray(), $this->workers),
            'barriers' => array_map(static fn (BarrierDefinition $barrier) => $barrier->toArray(), $this->barriers),
            'coordinator' => $this->coordinator,
            'timeouts' => [
                'scenario' => $this->scenarioTimeoutSeconds,
                'worker' => $this->workerTimeoutSeconds,
                'barrier' => $this->barrierTimeoutSeconds,
            ],
            'persist_trace' => $this->persistTrace,
            'determinism' => $this->determinism->value,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            runId: (string) $data['run_id'],
            name: (string) $data['name'],
            workers: array_map([WorkerDefinition::class, 'fromArray'], $data['workers'] ?? []),
            barriers: array_map([BarrierDefinition::class, 'fromArray'], $data['barriers'] ?? []),
            coordinator: (string) ($data['coordinator'] ?? 'file'),
            scenarioTimeoutSeconds: (float) ($data['timeouts']['scenario'] ?? 10),
            workerTimeoutSeconds: (float) ($data['timeouts']['worker'] ?? 8),
            barrierTimeoutSeconds: (float) ($data['timeouts']['barrier'] ?? 5),
            persistTrace: (bool) ($data['persist_trace'] ?? true),
            determinism: DeterminismLevel::from($data['determinism'] ?? DeterminismLevel::SchedulingDependent->value),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
