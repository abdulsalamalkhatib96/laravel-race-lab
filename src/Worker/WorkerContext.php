<?php

namespace RaceLab\LaravelRaceLab\Worker;

use RaceLab\LaravelRaceLab\Runtime\RuntimeContext;

final readonly class WorkerContext
{
    public function __construct(private RuntimeContext $runtime)
    {
    }

    public function id(): string { return $this->runtime->workerId(); }
    public function runId(): string { return $this->runtime->plan()->runId; }

    public function checkpoint(string $name, array $context = []): void
    {
        $this->runtime->manualCheckpoint($name, $context);
    }

    public function record(string $type, array $data = []): void
    {
        $this->runtime->record($type, $data);
    }
}
