<?php

namespace RaceLab\LaravelRaceLab;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use RaceLab\LaravelRaceLab\Console\RaceDoctorCommand;
use RaceLab\LaravelRaceLab\Console\RaceReplayCommand;
use RaceLab\LaravelRaceLab\Console\RaceWorkerCommand;
use RaceLab\LaravelRaceLab\Contracts\ActionSerializer;
use RaceLab\LaravelRaceLab\Contracts\WorkerExecutor;
use RaceLab\LaravelRaceLab\Coordination\CoordinatorManager;
use RaceLab\LaravelRaceLab\Coordination\FileCoordinator;
use RaceLab\LaravelRaceLab\Database\QueryInstrumentation;
use RaceLab\LaravelRaceLab\Database\QueryMatcher;
use RaceLab\LaravelRaceLab\Database\TransactionInstrumentation;
use RaceLab\LaravelRaceLab\Process\SubprocessExecutor;
use RaceLab\LaravelRaceLab\Results\FailureClassifier;
use RaceLab\LaravelRaceLab\Results\TimelineReporter;
use RaceLab\LaravelRaceLab\Runtime\RuntimeContext;
use RaceLab\LaravelRaceLab\Scenario\RaceFactory;
use RaceLab\LaravelRaceLab\Scenario\RaceRunner;
use RaceLab\LaravelRaceLab\Scenario\ScenarioCompiler;
use RaceLab\LaravelRaceLab\Serialization\LaravelActionSerializer;
use RaceLab\LaravelRaceLab\Support\RunStorage;
use RaceLab\LaravelRaceLab\Worker\WorkerRuntime;

final class LaravelRaceLabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/race-lab.php', 'race-lab');

        $this->app->singleton(RunStorage::class, function (Container $app): RunStorage {
            $path = (string) $app->make('config')->get('race-lab.storage_path', storage_path('framework/race-lab'));
            return new RunStorage($path);
        });

        $this->app->singleton(FileCoordinator::class, function (Container $app): FileCoordinator {
            return new FileCoordinator(
                $app->make(RunStorage::class),
                (int) $app->make('config')->get('race-lab.poll_interval_microseconds', 10_000),
            );
        });

        $this->app->singleton(CoordinatorManager::class, fn (Container $app) => new CoordinatorManager($app, $app->make(FileCoordinator::class)));

        $this->app->singleton(ActionSerializer::class, function (Container $app): ActionSerializer {
            return new LaravelActionSerializer((bool) $app->make('config')->get('race-lab.strict_closure_captures', false));
        });

        $this->app->singleton(RuntimeContext::class);
        $this->app->singleton(QueryMatcher::class);
        $this->app->singleton(FailureClassifier::class);
        $this->app->singleton(TimelineReporter::class);
        $this->app->singleton(ScenarioCompiler::class);

        $this->app->singleton(QueryInstrumentation::class, function (Container $app): QueryInstrumentation {
            return new QueryInstrumentation(
                $app->make('db'),
                $app->make('config'),
                $app->make('events'),
                $app->make(RuntimeContext::class),
                $app->make(QueryMatcher::class),
            );
        });

        $this->app->singleton(TransactionInstrumentation::class, function (Container $app): TransactionInstrumentation {
            return new TransactionInstrumentation(
                $app->make('db'),
                $app->make('config'),
                $app->make('events'),
                $app->make(RuntimeContext::class),
            );
        });

        $this->app->singleton(WorkerExecutor::class, fn () => new SubprocessExecutor(base_path()));
        $this->app->singleton(RaceRunner::class);
        $this->app->singleton(RaceFactory::class);
        $this->app->singleton(WorkerRuntime::class);
    }

    public function boot(): void
    {
        $key = (string) config('app.key', '');
        if ($key !== '') {
            SerializableClosure::setSecretKey(hash('sha256', $key.'|laravel-race-lab'));
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/race-lab.php' => config_path('race-lab.php'),
            ], 'race-lab-config');

            $this->commands([
                RaceWorkerCommand::class,
                RaceDoctorCommand::class,
                RaceReplayCommand::class,
            ]);
        }
    }
}
