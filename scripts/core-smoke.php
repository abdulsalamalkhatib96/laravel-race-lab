<?php
spl_autoload_register(function($class){
  $prefix='RaceLab\\LaravelRaceLab\\';
  if (!str_starts_with($class,$prefix)) return;
  $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
  if (is_file($path)) require $path;
});

use RaceLab\LaravelRaceLab\Coordination\FileCoordinator;
use RaceLab\LaravelRaceLab\Enums\DeterminismLevel;
use RaceLab\LaravelRaceLab\Scenario\{BarrierDefinition,ExecutionPlan,ManualPoint,QueryPoint,WorkerDefinition};
use RaceLab\LaravelRaceLab\Support\RunStorage;
use RaceLab\LaravelRaceLab\Database\QueryMatcher;
use RaceLab\LaravelRaceLab\Enums\QueryTiming;

$qp=QueryPoint::after()->select()->table('wallets')->contains('balance');
$m=new QueryMatcher();
assert($m->matches($qp->toArray(), QueryTiming::After, 'select `balance` from `wallets` where `id` = ?', 'mysql'));
assert(!$m->matches($qp->toArray(), QueryTiming::After, 'select `balance` from `users`', 'mysql'));

$base=sys_get_temp_dir().'/race-lab-smoke-'.bin2hex(random_bytes(4));
$storage=new RunStorage($base);
$coord=new FileCoordinator($storage, 1000);
$plan=new ExecutionPlan(
    runId:'run1', name:'smoke',
    workers:[new WorkerDefinition('A','x'), new WorkerDefinition('B','x')],
    barriers:[new BarrierDefinition('sync', ManualPoint::named('sync'), ['A','B'], true)],
    coordinator:'file', scenarioTimeoutSeconds:3, workerTimeoutSeconds:2, barrierTimeoutSeconds:2,
    persistTrace:true, determinism:DeterminismLevel::Deterministic
);
$coord->initialize($plan);
$coord->markReady('run1','A'); $coord->markReady('run1','B');
$coord->waitUntilAllReady('run1',['A','B'],1); $coord->releaseStart('run1');

$pids=[];
foreach (['A','B'] as $id) {
  $pid=pcntl_fork();
  if ($pid===0) {
    $c=new FileCoordinator(new RunStorage($base),1000);
    $c->arrive('run1','sync',$id);
    $c->waitForRelease('run1','sync',$id,2);
    exit(0);
  }
  $pids[]=$pid;
}
$coord->waitForBarrier('run1','sync',null,2);
foreach($pids as $pid){ pcntl_waitpid($pid,$status); assert(pcntl_wexitstatus($status)===0); }
$snap=$coord->snapshot('run1');
assert(count($snap['barriers']['sync']['arrived'])===2);
assert(count($snap['barriers']['sync']['released'])===2);
echo "SMOKE_OK\n";
$coord->cleanup('run1');
@rmdir($base);
