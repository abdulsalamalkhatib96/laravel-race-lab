<?php

namespace RaceLab\LaravelRaceLab\Scenario;

final class Schedule
{
    public function __construct(private RunningRace $race) {}

    public function waitFor(string $barrierId, ?array $workerIds = null, ?float $timeoutSeconds = null): self
    {
        $this->race->waitFor($barrierId, $workerIds, $timeoutSeconds); return $this;
    }

    public function release(string $barrierId, string|array|null $workers = null): self
    {
        $workerIds = is_string($workers) ? [$workers] : $workers;
        $this->race->release($barrierId, $workerIds); return $this;
    }

    public function releaseAll(): self
    {
        $this->race->releaseAll(); return $this;
    }
}
