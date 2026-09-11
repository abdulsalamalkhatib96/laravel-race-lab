<?php

namespace RaceLab\LaravelRaceLab\Scenario;

use InvalidArgumentException;
use RaceLab\LaravelRaceLab\Contracts\CheckpointDefinition;
use RaceLab\LaravelRaceLab\Enums\QueryOperation;
use RaceLab\LaravelRaceLab\Enums\QueryTiming;

final class QueryPoint implements CheckpointDefinition
{
    private ?string $connection = null;
    private ?QueryOperation $operation = null;
    private ?string $table = null;
    private ?string $sqlRegex = null;
    /** @var list<string> */
    private array $contains = [];
    private int $hit = 1;
    private bool $oncePerWorker = true;

    private function __construct(private QueryTiming $timing) {}

    public static function before(): self { return new self(QueryTiming::Before); }
    public static function after(): self { return new self(QueryTiming::After); }

    public function connection(string $connection): self
    {
        $clone = clone $this; $clone->connection = $connection; return $clone;
    }

    public function operation(QueryOperation|string $operation): self
    {
        $clone = clone $this;
        $clone->operation = $operation instanceof QueryOperation ? $operation : QueryOperation::from(strtolower($operation));
        return $clone;
    }

    public function select(): self { return $this->operation(QueryOperation::Select); }
    public function insert(): self { return $this->operation(QueryOperation::Insert); }
    public function update(): self { return $this->operation(QueryOperation::Update); }
    public function delete(): self { return $this->operation(QueryOperation::Delete); }

    public function table(string $table): self
    {
        $clone = clone $this; $clone->table = trim($table); return $clone;
    }

    public function sqlRegex(string $regex): self
    {
        if (@preg_match($regex, '') === false) throw new InvalidArgumentException('Invalid SQL regular expression.');
        $clone = clone $this; $clone->sqlRegex = $regex; return $clone;
    }

    public function contains(string ...$fragments): self
    {
        $clone = clone $this;
        $clone->contains = array_values(array_merge($clone->contains, $fragments));
        return $clone;
    }

    public function hit(int $number): self
    {
        if ($number < 1) throw new InvalidArgumentException('Query checkpoint hit must be >= 1.');
        $clone = clone $this; $clone->hit = $number; return $clone;
    }

    public function oncePerWorker(bool $enabled = true): self
    {
        $clone = clone $this; $clone->oncePerWorker = $enabled; return $clone;
    }

    public function type(): string { return 'query'; }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timing' => $this->timing->value,
            'connection' => $this->connection,
            'operation' => $this->operation?->value,
            'table' => $this->table,
            'sql_regex' => $this->sqlRegex,
            'contains' => $this->contains,
            'hit' => $this->hit,
            'once_per_worker' => $this->oncePerWorker,
        ];
    }
}
