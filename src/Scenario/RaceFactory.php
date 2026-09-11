<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use Illuminate\Contracts\Container\Container;

final class RaceFactory
{
    public function __construct(
        private ScenarioCompiler $compiler,
        private RaceRunner $runner,
        private Container $container,
    ) {}

    public function make(string $name): RaceBuilder
    {
        $config = $this->container->make('config');

        return new RaceBuilder(
            name: $name,
            compiler: $this->compiler,
            runner: $this->runner,
            defaults: [
                'coordinator' => (string) $config->get('race-lab.coordinator', 'file'),
                'scenario_timeout' => (float) $config->get('race-lab.timeouts.scenario', 10),
                'worker_timeout' => (float) $config->get('race-lab.timeouts.worker', 8),
                'barrier_timeout' => (float) $config->get('race-lab.timeouts.barrier', 5),
                'persist_trace' => (bool) $config->get('race-lab.trace.persist_successful_runs', false),
                'metadata' => [],
            ],
        );
    }
}
