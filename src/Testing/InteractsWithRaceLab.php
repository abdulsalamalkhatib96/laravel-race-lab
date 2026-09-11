<?php

namespace RaceLab\LaravelRaceLab\Testing;

use RaceLab\LaravelRaceLab\Scenario\RaceBuilder;
use RaceLab\LaravelRaceLab\Scenario\RaceFactory;

trait InteractsWithRaceLab
{
    protected function race(string $name): RaceBuilder
    {
        return app(RaceFactory::class)->make($name);
    }
}
