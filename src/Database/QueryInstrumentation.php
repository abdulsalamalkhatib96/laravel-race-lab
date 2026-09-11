<?php

namespace RaceLab\LaravelRaceLab\Database;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use RaceLab\LaravelRaceLab\Enums\QueryOperation;
use RaceLab\LaravelRaceLab\Enums\QueryTiming;
use RaceLab\LaravelRaceLab\Runtime\RuntimeContext;

final class QueryInstrumentation
{
    private bool $installed = false;
    /** @var array<int,bool> */
    private array $instrumentedConnections = [];
    /** @var array<string,int> */
    private array $hits = [];
    /** @var array<string,bool> */
    private array $triggered = [];

    public function __construct(
        private DatabaseManager $db,
        private Config $config,
        private Dispatcher $events,
        private RuntimeContext $runtime,
        private QueryMatcher $matcher,
    ) {
    }

    public function install(): void
    {
        if ($this->installed || ! $this->runtime->active()) return;
        $this->installed = true;

        $this->events->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->instrumentConnection($event->connection);
        });

        foreach ($this->db->getConnections() as $connection) {
            if ($connection instanceof Connection) $this->instrumentConnection($connection);
        }

        try {
            $default = $this->db->connection();
            if ($default instanceof Connection) $this->instrumentConnection($default);
        } catch (\Throwable $e) {
            $this->runtime->record('instrumentation.connection_skipped', [
                'connection' => (string) $this->config->get('database.default', 'default'),
                'reason' => $e->getMessage(),
            ]);
        }

        $this->events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->observe(
                QueryTiming::After,
                $event->sql,
                $event->bindings,
                $event->connectionName,
                $event->time,
            );
        });
    }

    private function instrumentConnection(Connection $connection): void
    {
        $id = spl_object_id($connection);
        if ($this->instrumentedConnections[$id] ?? false) return;
        $this->instrumentedConnections[$id] = true;

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            $this->observe(QueryTiming::Before, $query, $bindings, $connection->getName(), null);
        });
    }

    /** @param array<int|string,mixed> $bindings */
    private function observe(QueryTiming $timing, string $sql, array $bindings, string $connection, ?float $timeMs): void
    {
        if (! $this->runtime->active()) return;

        $event = [
            'timing' => $timing->value,
            'connection' => $connection,
            'operation' => QueryOperation::detect($sql)->value,
            'sql' => $sql,
        ];

        if ($timeMs !== null) $event['time_ms'] = $timeMs;
        if ((bool) $this->config->get('race-lab.database.capture_bindings', false)) {
            $event['bindings'] = $this->redactBindings($bindings);
        }

        if ((bool) $this->config->get('race-lab.database.capture_queries', true)) {
            $this->runtime->record('query.'.$timing->value, $event);
        }

        foreach ($this->runtime->plan()->barriers as $barrier) {
            if (! in_array($this->runtime->workerId(), $barrier->workers, true)) continue;

            $checkpoint = $barrier->checkpoint->toArray();
            if (($checkpoint['type'] ?? null) !== 'query') continue;
            if (! $this->matcher->matches($checkpoint, $timing, $sql, $connection)) continue;

            $this->hits[$barrier->id] = ($this->hits[$barrier->id] ?? 0) + 1;
            $requiredHit = (int) ($checkpoint['hit'] ?? 1);
            if ($this->hits[$barrier->id] < $requiredHit) continue;
            if (($checkpoint['once_per_worker'] ?? true) && ($this->triggered[$barrier->id] ?? false)) continue;

            $this->triggered[$barrier->id] = true;
            $this->runtime->hitBarrier($barrier->id, [
                'checkpoint_type' => 'query',
                'query_timing' => $timing->value,
                'connection' => $connection,
                'operation' => QueryOperation::detect($sql)->value,
                'sql' => $sql,
                'hit' => $this->hits[$barrier->id],
            ]);
        }
    }

    private function redactBindings(array $bindings): array
    {
        if (! (bool) $this->config->get('race-lab.database.redact_bindings', true)) return $bindings;

        return array_map(static function (mixed $value): mixed {
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
            if (is_string($value)) return '[redacted:string:'.strlen($value).']';
            return '[redacted:'.get_debug_type($value).']';
        }, $bindings);
    }
}
