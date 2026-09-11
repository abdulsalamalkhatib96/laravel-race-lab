<?php

namespace RaceLab\LaravelRaceLab\Testing;

/**
 * Marker/helper trait for race tests.
 *
 * It intentionally does NOT open a parent test transaction. Child workers use
 * independent PDO connections, so uncommitted parent fixtures would be invisible
 * (or lock rows) and invalidate the concurrency scenario.
 *
 * Prefer DatabaseMigrations / migrate:fresh / explicit cleanup in the host test suite.
 */
trait RaceDatabase
{
    protected function assertRaceDatabaseIsNotInsideTransaction(): void
    {
        if (! app()->bound('db')) return;

        foreach (array_keys((array) config('database.connections', [])) as $name) {
            try {
                $connection = app('db')->connection($name);
                if ($connection->transactionLevel() > 0) {
                    throw new \LogicException(
                        "Race Lab cannot start while test connection [{$name}] is inside a parent transaction. " .
                        'Do not use DatabaseTransactions/transactional RefreshDatabase for race tests; commit fixtures before spawning workers.'
                    );
                }
            } catch (\LogicException $e) {
                throw $e;
            } catch (\Throwable) {
                // Ignore unavailable optional connections.
            }
        }
    }
}
