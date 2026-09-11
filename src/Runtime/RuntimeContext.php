<?php

namespace RaceLab\LaravelRaceLab\Runtime;

use RaceLab\LaravelRaceLab\Contracts\Coordinator;
use RaceLab\LaravelRaceLab\Exceptions\RaceLabException;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;
use RaceLab\LaravelRaceLab\Scenario\ManualPoint;

final class RuntimeContext
{
    private ?ExecutionPlan $plan = null;
    private ?string $workerId = null;
    private ?Coordinator $coordinator = null;

    public function activate(ExecutionPlan $plan, string $workerId, Coordinator $coordinator): void
    {
        $this->plan = $plan;
        $this->workerId = $workerId;
        $this->coordinator = $coordinator;
    }

    public function deactivate(): void
    {
        $this->plan = null;
        $this->workerId = null;
        $this->coordinator = null;
    }

    public function active(): bool
    {
        return $this->plan !== null && $this->workerId !== null && $this->coordinator !== null;
    }

    public function plan(): ExecutionPlan
    {
        return $this->plan ?? throw new RaceLabException('Race Lab runtime context is not active.');
    }

    public function workerId(): string
    {
        return $this->workerId ?? throw new RaceLabException('Race Lab runtime context is not active.');
    }

    public function coordinator(): Coordinator
    {
        return $this->coordinator ?? throw new RaceLabException('Race Lab runtime context is not active.');
    }

    /** @param array<string,mixed> $context */
    public function hitBarrier(string $barrierId, array $context = []): void
    {
        if (! $this->active()) return;
        $barrier = $this->plan()->barrier($barrierId);
        if ($barrier === null) throw new RaceLabException("Unknown runtime barrier [{$barrierId}].");
        if (! in_array($this->workerId(), $barrier->workers, true)) return;

        $timeout = $barrier->timeoutSeconds ?? $this->plan()->barrierTimeoutSeconds;
        $this->coordinator()->arrive($this->plan()->runId, $barrierId, $this->workerId(), $context);
        $this->coordinator()->waitForRelease($this->plan()->runId, $barrierId, $this->workerId(), $timeout);
    }

    /** @param array<string,mixed> $context */
    public function manualCheckpoint(string $name, array $context = []): void
    {
        if (! $this->active()) return;

        $matched = false;
        foreach ($this->plan()->barriers as $barrier) {
            if ($barrier->checkpoint instanceof ManualPoint && $barrier->checkpoint->name === $name) {
                $matched = true;
                $this->hitBarrier($barrier->id, ['manual_checkpoint' => $name] + $context);
            }
        }

        if (! $matched) {
            $this->record('checkpoint.observed', ['manual_checkpoint' => $name] + $context);
        }
    }

    /** @param array<string,mixed> $data */
    public function record(string $type, array $data = []): void
    {
        if (! $this->active()) return;
        $this->coordinator()->record($this->plan()->runId, $type, $this->workerId(), $data);
    }
}
