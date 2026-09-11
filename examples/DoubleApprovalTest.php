<?php

use RaceLab\LaravelRaceLab\Scenario\QueryPoint;

it('approves a withdrawal at most once', function () {
    $withdrawalId = Withdrawal::factory()->pending()->create()->id;

    $result = race('withdrawal approval')
        ->workers(2)
        ->barrier('pending-read', QueryPoint::after()->select()->table('withdrawals'))
        ->run(fn () => app(WithdrawalService::class)->approve($withdrawalId));

    $result->assertExactlyOneSucceeded()->assertNoTimeouts();
    $result->assertDatabaseCount('wallet_transactions', 1);
});
