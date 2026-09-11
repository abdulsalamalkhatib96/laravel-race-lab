<?php

namespace RaceLab\LaravelRaceLab\Console;

use Illuminate\Console\Command;
use RaceLab\LaravelRaceLab\Coordination\CoordinatorManager;
use RaceLab\LaravelRaceLab\Support\RunStorage;
use Symfony\Component\Process\Process;

final class RaceDoctorCommand extends Command
{
    protected $signature = 'race-lab:doctor';
    protected $description = 'Check whether the application is ready to run deterministic race tests';

    public function handle(CoordinatorManager $coordinators, RunStorage $storage): int
    {
        $checks = [];
        $checks[] = ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=')];
        $checks[] = ['Laravel', method_exists(app(), 'version') ? app()->version() : 'unknown', true];
        $checks[] = ['Symfony Process', class_exists(Process::class) ? 'available' : 'missing', class_exists(Process::class)];
        $checks[] = ['Artisan', base_path('artisan'), is_file(base_path('artisan'))];
        $checks[] = ['Storage', $storage->basePath(), is_dir($storage->basePath()) ? is_writable($storage->basePath()) : is_writable(dirname($storage->basePath()))];

        $coordinator = (string) config('race-lab.coordinator', 'file');
        try {
            $coordinators->driver($coordinator);
            $checks[] = ['Coordinator', $coordinator, true];
        } catch (\Throwable $e) {
            $checks[] = ['Coordinator', $coordinator.' — '.$e->getMessage(), false];
        }

        try {
            $connection = app('db')->connection();
            $driver = (string) ($connection->getConfig('driver') ?? 'unknown');
            $dbOk = in_array($driver, ['mysql', 'mariadb', 'pgsql'], true);
            $note = $dbOk ? $driver : $driver.' (limited/experimental concurrency semantics)';
            $checks[] = ['Database', $note, true];
        } catch (\Throwable $e) {
            $checks[] = ['Database', 'unavailable — '.$e->getMessage(), false];
        }

        $environment = app()->environment();
        $allowProduction = (bool) config('race-lab.allow_production', false);
        $checks[] = ['Environment', $environment, $environment !== 'production' || $allowProduction];

        $this->newLine();
        $this->info('Laravel Race Lab Doctor');
        $this->table(['Check', 'Value', 'Status'], array_map(static fn (array $row) => [$row[0], $row[1], $row[2] ? 'OK' : 'FAIL'], $checks));

        $failed = array_filter($checks, static fn (array $row) => ! $row[2]);
        if ($failed !== []) {
            $this->error('Race Lab is not ready. Fix the failing checks above.');
            return self::FAILURE;
        }

        $this->info('Race Lab is ready.');
        return self::SUCCESS;
    }
}
