<?php

use RaceLab\LaravelRaceLab\Scenario\QueryPoint;

it('prevents a lost update', function () {
    $walletId = Wallet::factory()->create(['balance' => 100])->id;

    race('wallet concurrent increments')
        ->worker('A', fn () => app(WalletService::class)->add($walletId, 50))
        ->worker('B', fn () => app(WalletService::class)->add($walletId, 100))
        ->barrier('wallet-read', QueryPoint::after()->select()->table('wallets'))
        ->run()
        ->assertAllSucceeded()
        ->assertInvariant(fn () => (string) Wallet::findOrFail($walletId)->balance === '250.00');
});
