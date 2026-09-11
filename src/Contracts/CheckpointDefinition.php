<?php

namespace RaceLab\LaravelRaceLab\Contracts;

interface CheckpointDefinition
{
    public function type(): string;

    /** @return array<string,mixed> */
    public function toArray(): array;
}
