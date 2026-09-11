<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use InvalidArgumentException;
use RaceLab\LaravelRaceLab\Contracts\CheckpointDefinition;

final readonly class TransactionPoint implements CheckpointDefinition
{
    public const BEFORE_BEGIN = 'before_begin';
    public const BEGIN = 'begin';
    public const COMMITTING = 'committing';
    public const COMMIT = 'commit';
    public const ROLLBACK = 'rollback';

    private function __construct(public string $phase, public ?string $connection = null)
    {
        if (! in_array($phase, [self::BEFORE_BEGIN, self::BEGIN, self::COMMITTING, self::COMMIT, self::ROLLBACK], true)) {
            throw new InvalidArgumentException("Unsupported transaction checkpoint phase [{$phase}].");
        }
    }

    public static function beforeBegin(?string $connection = null): self { return new self(self::BEFORE_BEGIN, $connection); }
    public static function begin(?string $connection = null): self { return new self(self::BEGIN, $connection); }
    public static function committing(?string $connection = null): self { return new self(self::COMMITTING, $connection); }
    public static function commit(?string $connection = null): self { return new self(self::COMMIT, $connection); }
    public static function rollback(?string $connection = null): self { return new self(self::ROLLBACK, $connection); }
    public function type(): string { return 'transaction'; }

    public function toArray(): array
    {
        return ['type' => $this->type(), 'phase' => $this->phase, 'connection' => $this->connection];
    }
}
