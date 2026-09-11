<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use RaceLab\LaravelRaceLab\Contracts\CheckpointDefinition;

final readonly class BarrierDefinition
{
    /** @param list<string> $workers */
    public function __construct(
        public string $id,
        public CheckpointDefinition $checkpoint,
        public array $workers,
        public bool $autoRelease = true,
        public ?float $timeoutSeconds = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'checkpoint' => $this->checkpoint->toArray(),
            'workers' => $this->workers,
            'auto_release' => $this->autoRelease,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $checkpoint = CheckpointFactory::fromArray($data['checkpoint']);

        return new self(
            id: $data['id'],
            checkpoint: $checkpoint,
            workers: array_values($data['workers'] ?? []),
            autoRelease: (bool) ($data['auto_release'] ?? true),
            timeoutSeconds: isset($data['timeout_seconds']) ? (float) $data['timeout_seconds'] : null,
        );
    }
}
