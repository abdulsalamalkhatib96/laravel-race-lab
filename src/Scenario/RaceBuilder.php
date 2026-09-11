<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use Closure;
use RaceLab\LaravelRaceLab\Contracts\CheckpointDefinition;
use RaceLab\LaravelRaceLab\Exceptions\InvalidScenarioException;
use RaceLab\LaravelRaceLab\Results\RaceResult;

final class RaceBuilder
{
    /** @var array<string,array{action:Closure,metadata:array}> */
    private array $actions = [];
    /** @var list<BarrierDefinition> */
    private array $barriers = [];
    private ?int $replicaWorkers = null;
    private array $settings;
    private ?Closure $schedule = null;

    public function __construct(
        private string $name,
        private ScenarioCompiler $compiler,
        private RaceRunner $runner,
        array $defaults,
    ) {
        $this->settings = $defaults;
    }

    public function workers(int $count): self
    {
        if ($count < 2) throw new InvalidScenarioException('workers() must be at least 2.');
        if ($this->actions !== []) throw new InvalidScenarioException('workers() cannot be combined with explicit worker() definitions.');
        $clone = clone $this;
        $clone->replicaWorkers = $count;
        return $clone;
    }

    public function worker(string $id, Closure $action, array $metadata = []): self
    {
        if ($this->replicaWorkers !== null) throw new InvalidScenarioException('worker() cannot be combined with workers().');
        if ($id === '') throw new InvalidScenarioException('Worker ID cannot be empty.');
        if (isset($this->actions[$id])) throw new InvalidScenarioException("Duplicate worker ID [{$id}].");

        $clone = clone $this;
        $clone->actions[$id] = ['action' => $action, 'metadata' => $metadata];
        return $clone;
    }

    public function barrier(string|CheckpointDefinition $id, ?CheckpointDefinition $checkpoint = null, ?array $workers = null, bool $autoRelease = true, ?float $timeoutSeconds = null): self
    {
        if ($id instanceof CheckpointDefinition) {
            $checkpoint = $id;
            $id = 'barrier-'.(count($this->barriers) + 1);
        }
        if (! $checkpoint) throw new InvalidScenarioException('A barrier requires a checkpoint definition.');
        if ($id === '') throw new InvalidScenarioException('Barrier ID cannot be empty.');

        $clone = clone $this;
        $clone->barriers[] = new BarrierDefinition(
            id: $id,
            checkpoint: $checkpoint,
            workers: array_values($workers ?? []),
            autoRelease: $autoRelease,
            timeoutSeconds: $timeoutSeconds,
        );
        return $clone;
    }

    public function gate(string $id, CheckpointDefinition $checkpoint, ?array $workers = null, ?float $timeoutSeconds = null): self
    {
        return $this->barrier($id, $checkpoint, $workers, false, $timeoutSeconds);
    }

    public function manualBarrier(string $name, ?array $workers = null, bool $autoRelease = true, ?float $timeoutSeconds = null): self
    {
        return $this->barrier($name, ManualPoint::named($name), $workers, $autoRelease, $timeoutSeconds);
    }

    public function coordinator(string $driver): self
    {
        $clone = clone $this;
        $clone->settings['coordinator'] = $driver;
        return $clone;
    }

    public function timeout(float $seconds): self { return $this->withSetting('scenario_timeout', $seconds); }
    public function workerTimeout(float $seconds): self { return $this->withSetting('worker_timeout', $seconds); }
    public function barrierTimeout(float $seconds): self { return $this->withSetting('barrier_timeout', $seconds); }
    public function persistTrace(bool $persist = true): self { return $this->withSetting('persist_trace', $persist); }
    public function metadata(array $metadata): self { return $this->withSetting('metadata', $metadata); }

    public function run(?Closure $action = null): RaceResult
    {
        $running = $this->runAsync($action);
        if ($this->schedule !== null) {
            ($this->schedule)(new Schedule($running));
        }
        return $running->join();
    }

    public function schedule(Closure $schedule): self
    {
        $clone = clone $this;
        $clone->schedule = $schedule;
        return $clone;
    }

    public function runAsync(?Closure $action = null): RunningRace
    {
        $actions = $this->resolveActions($action);
        $plan = $this->compiler->compile($this->name, $actions, $this->barriers, $this->settings);
        return $this->runner->start($plan);
    }

    private function resolveActions(?Closure $action): array
    {
        if ($this->replicaWorkers !== null) {
            if (! $action) throw new InvalidScenarioException('run() requires a worker closure when workers() is used.');
            $actions = [];
            for ($i = 1; $i <= $this->replicaWorkers; $i++) {
                $actions['worker-'.$i] = ['action' => $action, 'metadata' => ['replica' => $i]];
            }
            return $actions;
        }

        if ($action !== null) {
            if ($this->actions !== []) throw new InvalidScenarioException('run($closure) cannot be combined with explicit worker() definitions.');
            throw new InvalidScenarioException('Use workers(n)->run($closure), or define workers explicitly with worker().');
        }

        return $this->actions;
    }

    private function withSetting(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->settings[$key] = $value;
        return $clone;
    }
}
