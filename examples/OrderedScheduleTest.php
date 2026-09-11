<?php

use RaceLab\LaravelRaceLab\Scenario\QueryPoint;

it('forces A to write before B', function () {
    $id = Wallet::factory()->create(['balance' => 100])->id;

    $result = race('ordered writes')
        ->worker('A', fn () => app(Service::class)->work($id, 'A'))
        ->worker('B', fn () => app(Service::class)->work($id, 'B'))
        ->gate('before-write', QueryPoint::before()->update()->table('wallets'))
        ->barrier('a-written', QueryPoint::after()->update()->table('wallets'), workers: ['A'])
        ->schedule(fn ($schedule) => $schedule
            ->waitFor('before-write', ['A', 'B'])
            ->release('before-write', 'A')
            ->waitFor('a-written', ['A'])
            ->release('before-write', 'B'))
        ->run();

    $result->assertAllSucceeded();
});
