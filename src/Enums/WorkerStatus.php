<?php

namespace RaceLab\LaravelRaceLab\Enums;

enum WorkerStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Crashed = 'crashed';
    case TimedOut = 'timed_out';
    case Aborted = 'aborted';
}
