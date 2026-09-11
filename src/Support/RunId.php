<?php

namespace RaceLab\LaravelRaceLab\Support;

final class RunId
{
    public static function make(): string
    {
        $time = dechex((int) floor(microtime(true) * 1000));
        $random = bin2hex(random_bytes(8));

        return strtolower($time.'-'.$random);
    }
}
