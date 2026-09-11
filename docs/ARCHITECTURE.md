# Architecture

```text
Test DSL
  -> ScenarioCompiler -> immutable ExecutionPlan
  -> RaceRunner
      -> Coordinator (file / Redis)
      -> SubprocessExecutor
          -> fresh PHP + Laravel worker A
          -> fresh PHP + Laravel worker B
                -> QueryInstrumentation
                -> TransactionInstrumentation
                -> Manual checkpoints
      -> RunningRace orchestration
      -> RaceResult + timeline + persisted replay artifacts
```

## Invariants

1. Workers never share PDO connections or the parent Laravel container.
2. Workers operate on the same external DB/cache resources unless the application itself routes them otherwise.
3. Synchronization never uses the database under test. File locks or Redis coordinate the test so DB locking semantics remain observable.
4. Timing is not used as the correctness primitive. Barriers/gates coordinate execution; sleeps are unnecessary.
5. Worker application exceptions are test outcomes. A process exiting without publishing a result is a worker crash.
6. Barrier and scenario timeouts guarantee a broken scenario cannot hang the suite forever.

## Barrier lifecycle

`created -> arrived(partial) -> satisfied -> released`

A gate is simply a barrier with `auto_release=false`. `RunningRace::waitFor()` and `release()` provide controlled interleavings.

## Instrumentation

`Connection::beforeExecuting()` implements before-query checkpoints. Global `QueryExecuted` events implement after-query checkpoints. `ConnectionEstablished` is observed so named/dynamic Laravel connections created after boot are instrumented too.

Transaction events cover begin/committing/commit/rollback and `beforeStartingTransaction()` covers before-begin.

## Failure classes

Application, deadlock, serialization failure, timeout, worker crash, infrastructure.

## Artifacts

Plans contain signed serialized closures. Treat plan files as sensitive test artifacts and never execute untrusted plans.
