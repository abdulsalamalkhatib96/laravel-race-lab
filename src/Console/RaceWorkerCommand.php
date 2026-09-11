<?php

namespace RaceLab\LaravelRaceLab\Console;

use Illuminate\Console\Command;
use RaceLab\LaravelRaceLab\Worker\WorkerRuntime;

final class RaceWorkerCommand extends Command
{
    protected $signature = 'race-lab:worker {--plan= : Absolute path to the execution plan} {--worker= : Worker ID}';
    protected $description = 'Internal Laravel Race Lab worker process';

    public function handle(WorkerRuntime $runtime): int
    {
        $plan = (string) $this->option('plan');
        $worker = (string) $this->option('worker');

        if (app()->environment('production') && ! (bool) config('race-lab.allow_production', false)) {
            $this->error('Laravel Race Lab worker execution is disabled in production.');
            return self::FAILURE;
        }

        if ($plan === '' || $worker === '') {
            $this->error('Both --plan and --worker are required.');
            return self::FAILURE;
        }

        try {
            return $runtime->run($plan, $worker);
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());
            if ($this->getOutput()->isVerbose()) $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
