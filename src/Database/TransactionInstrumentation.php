<?php

namespace RaceLab\LaravelRaceLab\Database;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use RaceLab\LaravelRaceLab\Runtime\RuntimeContext;

final class TransactionInstrumentation
{
    private bool $installed = false;
    /** @var array<int,bool> */
    private array $instrumentedConnections = [];
    /** @var array<string,bool> */
    private array $triggered = [];

    public function __construct(
        private DatabaseManager $db,
        private Config $config,
        private Dispatcher $events,
        private RuntimeContext $runtime,
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
        } catch (\Throwable) {
        }

        $this->events->listen(TransactionBeginning::class, fn (TransactionBeginning $event) => $this->observe('begin', $event->connectionName));
        $this->events->listen(TransactionCommitting::class, fn (TransactionCommitting $event) => $this->observe('committing', $event->connectionName));
        $this->events->listen(TransactionCommitted::class, fn (TransactionCommitted $event) => $this->observe('commit', $event->connectionName));
        $this->events->listen(TransactionRolledBack::class, fn (TransactionRolledBack $event) => $this->observe('rollback', $event->connectionName));
    }

    private function instrumentConnection(Connection $connection): void
    {
        $id = spl_object_id($connection);
        if ($this->instrumentedConnections[$id] ?? false) return;
        $this->instrumentedConnections[$id] = true;
        $name = $connection->getName();

        $connection->beforeStartingTransaction(function (...$unused) use ($name): void {
            $this->observe('before_begin', $name);
        });
    }

    private function observe(string $phase, string $connection): void
    {
        if (! $this->runtime->active()) return;
        $this->runtime->record('transaction.'.$phase, ['connection' => $connection, 'phase' => $phase]);

        foreach ($this->runtime->plan()->barriers as $barrier) {
            if (! in_array($this->runtime->workerId(), $barrier->workers, true)) continue;
            $checkpoint = $barrier->checkpoint->toArray();
            if (($checkpoint['type'] ?? null) !== 'transaction') continue;
            if (($checkpoint['phase'] ?? null) !== $phase) continue;
            if (! empty($checkpoint['connection']) && $checkpoint['connection'] !== $connection) continue;
            if ($this->triggered[$barrier->id] ?? false) continue;

            $this->triggered[$barrier->id] = true;
            $this->runtime->hitBarrier($barrier->id, [
                'checkpoint_type' => 'transaction',
                'phase' => $phase,
                'connection' => $connection,
            ]);
        }
    }
}
