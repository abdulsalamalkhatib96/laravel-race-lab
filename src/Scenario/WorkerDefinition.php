<?php

namespace RaceLab\LaravelRaceLab\Scenario;

final readonly class WorkerDefinition
{
    public function __construct(
        public string $id,
        public string $actionPayload,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'action_payload' => $this->actionPayload, 'metadata' => $this->metadata];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            actionPayload: (string) $data['action_payload'],
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
