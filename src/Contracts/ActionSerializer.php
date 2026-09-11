<?php

namespace RaceLab\LaravelRaceLab\Contracts;

use Closure;

interface ActionSerializer
{
    public function serialize(Closure $closure): string;

    public function unserialize(string $payload): Closure;
}
