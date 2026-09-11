<?php
namespace RaceLab\LaravelRaceLab\Tests\Unit;
use PHPUnit\Framework\TestCase;
use RaceLab\LaravelRaceLab\Enums\DeterminismLevel;
use RaceLab\LaravelRaceLab\Scenario\{BarrierDefinition,ExecutionPlan,QueryPoint,WorkerDefinition};
final class ExecutionPlanTest extends TestCase
{
    public function test_plan_round_trips_through_json_shape(): void
    {
        $plan=new ExecutionPlan('x','lost update',[new WorkerDefinition('A','payload'),new WorkerDefinition('B','payload')],[new BarrierDefinition('read',QueryPoint::after()->select()->table('wallets'),['A','B'])],'file',10,8,5,true,DeterminismLevel::Deterministic);
        $copy=ExecutionPlan::fromArray(json_decode(json_encode($plan->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
        self::assertSame($plan->toArray(),$copy->toArray());
    }
}
