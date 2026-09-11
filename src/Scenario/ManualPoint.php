<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use RaceLab\LaravelRaceLab\Contracts\CheckpointDefinition;

final readonly class ManualPoint implements CheckpointDefinition
{
    public function __construct(public string $name) {}

    public static function named(string $name): self { return new self($name); }
    public function type(): string { return 'manual'; }

    public function toArray(): array
    {
        return ['type' => $this->type(), 'name' => $this->name];
    }
}
