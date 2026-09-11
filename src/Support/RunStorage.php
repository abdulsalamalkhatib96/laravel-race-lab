<?php

namespace RaceLab\LaravelRaceLab\Support;

use RaceLab\LaravelRaceLab\Exceptions\RaceLabException;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;

final class RunStorage
{
    public function __construct(private string $basePath)
    {
        $this->basePath = rtrim($this->basePath, DIRECTORY_SEPARATOR);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function runPath(string $runId): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$runId;
    }

    public function planPath(string $runId): string
    {
        return $this->runPath($runId).DIRECTORY_SEPARATOR.'plan.json';
    }

    public function statePath(string $runId): string
    {
        return $this->runPath($runId).DIRECTORY_SEPARATOR.'state.json';
    }

    public function tracePath(string $runId): string
    {
        return $this->runPath($runId).DIRECTORY_SEPARATOR.'trace.jsonl';
    }

    public function lockPath(string $runId): string
    {
        return $this->runPath($runId).DIRECTORY_SEPARATOR.'state.lock';
    }

    public function ensureRun(string $runId): string
    {
        $path = $this->runPath($runId);
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RaceLabException("Unable to create Race Lab run directory [{$path}].");
        }

        @chmod($path, 0700);

        return $path;
    }

    public function writePlan(ExecutionPlan $plan): string
    {
        $this->ensureRun($plan->runId);
        $path = $this->planPath($plan->runId);
        $json = json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RaceLabException("Unable to write execution plan [{$path}].");
        }

        @chmod($path, 0600);

        return $path;
    }

    /** @param array<string,mixed> $result */
    public function writeResult(string $runId, array $result): string
    {
        $this->ensureRun($runId);
        $path = $this->runPath($runId).DIRECTORY_SEPARATOR.'result.json';
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RaceLabException("Unable to write Race Lab result [{$path}].");
        }
        @chmod($path, 0600);
        return $path;
    }

    public function readPlan(string $path): ExecutionPlan
    {
        if (! is_file($path)) {
            throw new RaceLabException("Race Lab execution plan not found [{$path}].");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RaceLabException("Unable to read execution plan [{$path}].");
        }

        return ExecutionPlan::fromArray(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function deleteRun(string $runId): void
    {
        $path = $this->runPath($runId);
        if (! is_dir($path)) return;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
