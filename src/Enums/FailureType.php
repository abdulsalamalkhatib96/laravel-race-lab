<?php

namespace RaceLab\LaravelRaceLab\Enums;

enum FailureType: string
{
    case Application = 'application';
    case Deadlock = 'deadlock';
    case SerializationFailure = 'serialization_failure';
    case Timeout = 'timeout';
    case WorkerCrash = 'worker_crash';
    case Infrastructure = 'infrastructure';
}
