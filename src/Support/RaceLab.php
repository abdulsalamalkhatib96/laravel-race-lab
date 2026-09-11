<?php

namespace RaceLab\LaravelRaceLab\Support;

use Illuminate\Container\Container;
use RaceLab\LaravelRaceLab\Runtime\RuntimeContext;

final class RaceLab
{
    public static function active(): bool
    {
        $container = Container::getInstance();
        return $container !== null && $container->bound(RuntimeContext::class) && $container->make(RuntimeContext::class)->active();
    }

    public static function checkpoint(string $name, array $context = []): void
    {
        $container = Container::getInstance();
        if ($container === null || ! $container->bound(RuntimeContext::class)) return;
        $container->make(RuntimeContext::class)->manualCheckpoint($name, $context);
    }

    public static function record(string $type, array $data = []): void
    {
        $container = Container::getInstance();
        if ($container === null || ! $container->bound(RuntimeContext::class)) return;
        $container->make(RuntimeContext::class)->record($type, $data);
    }
}
