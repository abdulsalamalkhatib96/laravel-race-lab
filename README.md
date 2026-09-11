# Laravel Race Lab

Deterministic concurrency and race-condition testing for Laravel.

Instead of running a test hundreds of times and hoping the scheduler exposes a race, Race Lab launches fresh Laravel PHP worker processes against the **same database** and pauses them at deterministic checkpoints.

## Install

Until the first Packagist release, install directly from this GitHub repository:

```bash
composer config repositories.race-lab vcs https://github.com/abdulsalamalkhatib96/laravel-race-lab
composer require --dev race-lab/laravel-race-lab:dev-main

php artisan vendor:publish --tag=race-lab-config
php artisan race-lab:doctor
```

After the package is published on Packagist, installation becomes:

```bash
composer require --dev race-lab/laravel-race-lab
```

Supported target: PHP 8.2+, Laravel 11/12/13. MySQL/MariaDB and PostgreSQL are first-class targets. SQLite has different locking semantics and should not be used to prove production concurrency correctness.

## Lost update

```php
use RaceLab\LaravelRaceLab\Scenario\QueryPoint;

it('does not lose concurrent wallet increments', function () {
    $walletId = Wallet::factory()->create(['balance' => 100])->id;

    $result = race('wallet increment is atomic')
        ->worker('A', fn () => app(WalletService::class)->add($walletId, 50))
        ->worker('B', fn () => app(WalletService::class)->add($walletId, 100))
        ->barrier(
            'wallet-read',
            QueryPoint::after()->select()->table('wallets')->oncePerWorker()
        )
        ->run();

    $result->assertAllSucceeded();
    expect(Wallet::find($walletId)->balance)->toBe('250.00');
});
```

Both workers reach the SELECT and block. The barrier auto-releases only after both arrived.

## Double approval

```php
$result = race('withdrawal cannot be approved twice')
    ->workers(2)
    ->barrier(
        'pending-read',
        QueryPoint::after()->select()->table('withdrawals')
    )
    ->run(function () use ($withdrawalId) {
        app(WithdrawalService::class)->approve($withdrawalId);
    });

$result
    ->assertExactlyOneSucceeded()
    ->assertNoTimeouts();

$result->assertDatabaseCount('wallet_transactions', 1);
```

Capture scalar IDs in worker closures and resolve Models/services inside the worker. Each worker boots a fresh Laravel container and fresh PDO connection.

## Controlled interleaving

Use a non-auto-releasing gate and a parent schedule when exact order matters:

```php
$result = race('controlled write order')
    ->worker('A', fn () => app(Service::class)->work($id, 'A'))
    ->worker('B', fn () => app(Service::class)->work($id, 'B'))
    ->gate('before-write', QueryPoint::before()->update()->table('wallets'))
    ->barrier(
        'a-write-finished',
        QueryPoint::after()->update()->table('wallets'),
        workers: ['A']
    )
    ->schedule(function ($schedule) {
        $schedule
            ->waitFor('before-write', ['A', 'B'])
            ->release('before-write', 'A')
            ->waitFor('a-write-finished', ['A']) // proves A actually wrote
            ->release('before-write', 'B');
    })
    ->run();
```

For interactive orchestration:

```php
$run = race('scenario')
    ->worker('A', $a)
    ->worker('B', $b)
    ->gate('read', QueryPoint::after()->select()->table('wallets'))
    ->runAsync();

$run->waitFor('read', ['A', 'B']);
$run->release('read', ['A']);
$run->release('read', ['B']);
$result = $run->join();
```

## Manual checkpoints

```php
use RaceLab\LaravelRaceLab\Support\RaceLab;

// production code: no-op unless a Race Lab worker is active
RaceLab::checkpoint('eligibility-checked');
```

```php
race('check then act')
    ->workers(2)
    ->manualBarrier('eligibility-checked')
    ->run(fn () => app(Service::class)->execute($id));
```

## Transaction checkpoints

```php
use RaceLab\LaravelRaceLab\Scenario\TransactionPoint;

->barrier('tx-open', TransactionPoint::begin())
->gate('before-commit', TransactionPoint::committing())
```

Available phases: `beforeBegin`, `begin`, `committing`, `commit`, `rollback`.

## Query matching

```php
QueryPoint::before()
    ->connection('mysql')
    ->update()
    ->table('wallets')
    ->contains('balance')
    ->hit(2)
    ->oncePerWorker();
```

For complex/raw/vendor-specific SQL:

```php
QueryPoint::after()->sqlRegex('/select .* for update/i');
```

The built-in table matcher intentionally stays small rather than pretending to be a full SQL parser.

## Redis coordinator

File coordination is the default and requires no service. For shared/containerized CI:

```env
RACE_LAB_COORDINATOR=redis
RACE_LAB_REDIS_CONNECTION=default
```

Redis keys use TTLs so interrupted runs do not leave permanent coordination state.

## Assertions

```php
$result->assertAllSucceeded();
$result->assertExactlyOneSucceeded();
$result->assertExactlyOneFailed();
$result->assertWorkerSucceeded('A');
$result->assertWorkerFailed('B');
$result->assertNoTimeouts();
$result->assertNoDeadlocks();
$result->assertExceptionCount(DuplicatePaymentException::class, 1);
$result->assertDatabaseCount('payment_attempts', 1);
$result->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
$result->assertInvariant(fn () => Wallet::find($id)->balance === '250.00');
```

Race Lab deliberately does **not** provide a generic `assertNoLostUpdate()`: only the application knows its business invariant.

## Trace and replay

Failed runs are preserved under:

```text
storage/framework/race-lab/<run-id>/
  plan.json
  state.json
  trace.jsonl
  result.json
```

Render a timeline:

```php
echo $result->report();
```

Replay a persisted plan:

```bash
php artisan race-lab:replay storage/framework/race-lab/<run-id>
```

Successful traces are deleted by default; set `RACE_LAB_PERSIST_SUCCESS=true` or call `->persistTrace()`.

## Critical database rule

Do not wrap a race test in a parent `DatabaseTransactions` / transactional `RefreshDatabase` transaction. Workers use independent PDO connections, so uncommitted fixtures may be invisible and parent locks can create artificial behavior. Race Lab detects active parent transactions by default and refuses to start.

Prepare and **commit** fixtures, run the race, assert, then clean up.

## Production guard

Race Lab refuses to execute in `production` by default. It is intended as a `require-dev` dependency. Enabling `RACE_LAB_ALLOW_PRODUCTION=true` is intentionally explicit and strongly discouraged.

## Commands

```bash
php artisan race-lab:doctor
php artisan race-lab:replay <run-directory-or-plan.json>
```

`race-lab:worker` is internal and is spawned by the runtime.

## Architecture

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
