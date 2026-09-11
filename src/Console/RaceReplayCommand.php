<?php

namespace RaceLab\LaravelRaceLab\Console;

use Illuminate\Console\Command;
use RaceLab\LaravelRaceLab\Results\TimelineReporter;
use RaceLab\LaravelRaceLab\Scenario\RaceRunner;
use RaceLab\LaravelRaceLab\Support\RunId;
use RaceLab\LaravelRaceLab\Support\RunStorage;

final class RaceReplayCommand extends Command
{
    protected $signature = 'race-lab:replay {path : Run directory or plan.json path}';
    protected $description = 'Replay a previously persisted Race Lab execution plan with a new run ID';

    public function handle(RunStorage $storage, RaceRunner $runner, TimelineReporter $reporter): int
    {
        $path = (string) $this->argument('path');
        if (is_dir($path)) $path = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plan.json';

        try {
            $plan = $storage->readPlan($path)->withRunId(RunId::make());
            $result = $runner->start($plan)->join();
            $this->line($reporter->render($result));
            return $result->allSucceeded() ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
