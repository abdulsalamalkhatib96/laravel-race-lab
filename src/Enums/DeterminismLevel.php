<?php

namespace RaceLab\LaravelRaceLab\Enums;

enum DeterminismLevel: string
{
    case Deterministic = 'deterministic';
    case SemiDeterministic = 'semi_deterministic';
    case SchedulingDependent = 'scheduling_dependent';
}
