<?php
namespace RaceLab\LaravelRaceLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceLab\LaravelRaceLab\Database\QueryMatcher;
use RaceLab\LaravelRaceLab\Enums\QueryTiming;
use RaceLab\LaravelRaceLab\Scenario\QueryPoint;

final class QueryMatcherTest extends TestCase
{
    public function test_it_matches_generated_sql_across_identifier_quotes(): void
    {
        $point = QueryPoint::after()->select()->table('wallets')->contains('balance');
        $matcher = new QueryMatcher();
        self::assertTrue($matcher->matches($point->toArray(), QueryTiming::After, 'select `balance` from `wallets` where `id` = ?', 'mysql'));
        self::assertTrue($matcher->matches($point->toArray(), QueryTiming::After, 'select "balance" from "wallets" where "id" = $1', 'pgsql'));
        self::assertFalse($matcher->matches($point->toArray(), QueryTiming::Before, 'select `balance` from `wallets`', 'mysql'));
        self::assertFalse($matcher->matches($point->toArray(), QueryTiming::After, 'select `balance` from `users`', 'mysql'));
    }
}
