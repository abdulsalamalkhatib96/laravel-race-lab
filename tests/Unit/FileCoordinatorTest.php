<?php
namespace RaceLab\LaravelRaceLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceLab\LaravelRaceLab\Coordination\FileCoordinator;
use RaceLab\LaravelRaceLab\Enums\DeterminismLevel;
use RaceLab\LaravelRaceLab\Scenario\BarrierDefinition;
use RaceLab\LaravelRaceLab\Scenario\ExecutionPlan;
use RaceLab\LaravelRaceLab\Scenario\ManualPoint;
use RaceLab\LaravelRaceLab\Scenario\WorkerDefinition;
use RaceLab\LaravelRaceLab\Support\RunStorage;

final class FileCoordinatorTest extends TestCase
{
    public function test_two_os_processes_wait_at_same_barrier_and_release_deterministically(): void
    {
        if (! function_exists('pcntl_fork')) self::markTestSkipped('pcntl required');
        $base = sys_get_temp_dir().'/race-lab-test-'.bin2hex(random_bytes(4));
        $storage = new RunStorage($base);
        $coordinator = new FileCoordinator($storage, 1000);
        $plan = new ExecutionPlan('run','test', [new WorkerDefinition('A','x'),new WorkerDefinition('B','x')], [new BarrierDefinition('sync', ManualPoint::named('sync'), ['A','B'])], 'file', 3,2,2,true, DeterminismLevel::Deterministic);
        $coordinator->initialize($plan);
        $pids=[];
        foreach(['A','B'] as $id){
            $pid=pcntl_fork();
            if($pid===0){ $child=new FileCoordinator(new RunStorage($base),1000); $child->arrive('run','sync',$id); $child->waitForRelease('run','sync',$id,2); exit(0); }
            $pids[]=$pid;
        }
        $coordinator->waitForBarrier('run','sync',null,2);
        foreach($pids as $pid){ pcntl_waitpid($pid,$status); self::assertSame(0,pcntl_wexitstatus($status)); }
        $state=$coordinator->snapshot('run');
        self::assertCount(2,$state['barriers']['sync']['arrived']);
        self::assertCount(2,$state['barriers']['sync']['released']);
        $coordinator->cleanup('run'); @rmdir($base);
    }
}
