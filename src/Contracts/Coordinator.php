<?php

namespace RaceLab\LaravelRaceLab\Contracts;

use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;

interface Coordinator
{
    public function initialize(ExecutionPlan $plan): void;

    public function markReady(string $runId, string $workerId): void;

    public function waitUntilAllReady(string $runId, array $workerIds, float $timeoutSeconds): void;

    public function releaseStart(string $runId): void;

    public function waitForStart(string $runId, string $workerId, float $timeoutSeconds): void;

    /** @param array<string,mixed> $context */
    public function arrive(string $runId, string $barrierId, string $workerId, array $context = []): void;

    public function waitForRelease(string $runId, string $barrierId, string $workerId, float $timeoutSeconds): void;

    public function waitForBarrier(string $runId, string $barrierId, ?array $workerIds, float $timeoutSeconds): void;

    public function release(string $runId, string $barrierId, ?array $workerIds = null): void;

    /** @param array<string,mixed> $result */
    public function markWorkerResult(string $runId, string $workerId, array $result): void;

    /** @return array<string,array<string,mixed>> */
    public function workerResults(string $runId): array;

    /** @param array<string,mixed> $data */
    public function record(string $runId, string $type, ?string $workerId = null, array $data = []): void;

    /** @return list<array<string,mixed>> */
    public function events(string $runId): array;

    /** @return array<string,mixed> */
    public function snapshot(string $runId): array;

    public function complete(string $runId, bool $success): void;

    public function abort(string $runId, string $reason): void;

    public function isAborted(string $runId): bool;

    public function cleanup(string $runId): void;
}
