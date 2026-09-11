<?php

namespace RaceLab\LaravelRaceLab\Results;

use RaceLab\LaravelRaceLab\Enums\FailureType;
use RaceLab\LaravelRaceLab\Enums\WorkerStatus;

final readonly class WorkerResult
{
    public function __construct(
        public string $workerId,
        public WorkerStatus $status,
        public mixed $returnValue,
        public ?ThrowableSnapshot $exception,
        public ?FailureType $failureType,
        public float $startedAt,
        public float $finishedAt,
        public ?int $processExitCode = null,
        public string $processOutput = '',
        public string $processErrorOutput = '',
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === WorkerStatus::Succeeded;
    }

    public function failed(): bool
    {
        return ! $this->succeeded();
    }

    public function durationMilliseconds(): float
    {
        return max(0, ($this->finishedAt - $this->startedAt) * 1000);
    }

    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'status' => $this->status->value,
            'return_value' => $this->returnValue,
            'exception' => $this->exception?->toArray(),
            'failure_type' => $this->failureType?->value,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'process_exit_code' => $this->processExitCode,
            'process_output' => $this->processOutput,
            'process_error_output' => $this->processErrorOutput,
        ];
    }

    public static function fromArray(array $data, ?int $processExitCode = null, string $output = '', string $errorOutput = ''): self
    {
        return new self(
            workerId: (string) $data['worker_id'],
            status: WorkerStatus::from($data['status']),
            returnValue: $data['return_value'] ?? null,
            exception: ThrowableSnapshot::fromArray($data['exception'] ?? null),
            failureType: isset($data['failure_type']) && $data['failure_type'] !== null ? FailureType::from($data['failure_type']) : null,
            startedAt: (float) ($data['started_at'] ?? 0),
            finishedAt: (float) ($data['finished_at'] ?? 0),
            processExitCode: $processExitCode,
            processOutput: $output,
            processErrorOutput: $errorOutput,
        );
    }
}
