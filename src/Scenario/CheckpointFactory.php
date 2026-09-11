<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use RaceLab\LaravelRaceLab\Contracts\CheckpointDefinition;
use RaceLab\LaravelRaceLab\Exceptions\InvalidScenarioException;

final class CheckpointFactory
{
    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): CheckpointDefinition
    {
        return match ($data['type'] ?? null) {
            'manual' => new ManualPoint((string) $data['name']),
            'transaction' => self::transaction($data),
            'query' => self::query($data),
            default => throw new InvalidScenarioException('Unknown checkpoint type in execution plan.'),
        };
    }

    /** @param array<string,mixed> $data */
    private static function transaction(array $data): TransactionPoint
    {
        return match ($data['phase'] ?? null) {
            TransactionPoint::BEFORE_BEGIN => TransactionPoint::beforeBegin($data['connection'] ?? null),
            TransactionPoint::BEGIN => TransactionPoint::begin($data['connection'] ?? null),
            TransactionPoint::COMMITTING => TransactionPoint::committing($data['connection'] ?? null),
            TransactionPoint::COMMIT => TransactionPoint::commit($data['connection'] ?? null),
            TransactionPoint::ROLLBACK => TransactionPoint::rollback($data['connection'] ?? null),
            default => throw new InvalidScenarioException('Unknown transaction checkpoint phase.'),
        };
    }

    /** @param array<string,mixed> $data */
    private static function query(array $data): QueryPoint
    {
        $point = ($data['timing'] ?? 'after') === 'before' ? QueryPoint::before() : QueryPoint::after();

        if (! empty($data['connection'])) $point = $point->connection($data['connection']);
        if (! empty($data['operation'])) $point = $point->operation($data['operation']);
        if (! empty($data['table'])) $point = $point->table($data['table']);
        if (! empty($data['sql_regex'])) $point = $point->sqlRegex($data['sql_regex']);
        if (! empty($data['contains'])) $point = $point->contains(...array_values($data['contains']));
        if (! empty($data['hit'])) $point = $point->hit((int) $data['hit']);
        if (array_key_exists('once_per_worker', $data)) $point = $point->oncePerWorker((bool) $data['once_per_worker']);

        return $point;
    }
}
