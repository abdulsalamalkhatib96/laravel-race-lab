<?php

use RaceLab\LaravelRaceLab\Scenario\RaceBuilder;
use RaceLab\LaravelRaceLab\Scenario\RaceFactory;

if (! function_exists('race')) {
    function race(string $name): RaceBuilder
    {
        return app(RaceFactory::class)->make($name);
    }
}
