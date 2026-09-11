<?php

namespace RaceLab\LaravelRaceLab\Results;

use RaceLab\LaravelRaceLab\Enums\FailureType;
use RaceLab\LaravelRaceLab\Exceptions\BarrierTimeoutException;
use RaceLab\LaravelRaceLab\Exceptions\WorkerTimeoutException;

final class FailureClassifier
{
    public function classify(\Throwable $e): FailureType
    {
        if ($e instanceof BarrierTimeoutException || $e instanceof WorkerTimeoutException) {
            return FailureType::Timeout;
        }

        $message = strtolower($e->getMessage());
        $code = (string) $e->getCode();

        if (
            str_contains($message, 'deadlock') ||
            str_contains($message, 'lock wait timeout') ||
            in_array($code, ['1213', '1205', '40P01'], true)
        ) {
            return FailureType::Deadlock;
        }

        if (
            str_contains($message, 'serialization failure') ||
            str_contains($message, 'could not serialize access') ||
            $code === '40001'
        ) {
            return FailureType::SerializationFailure;
        }

        return FailureType::Application;
    }
}
