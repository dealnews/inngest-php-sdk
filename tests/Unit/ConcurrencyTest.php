<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\Concurrency;
use PHPUnit\Framework\TestCase;

class ConcurrencyTest extends TestCase
{
    public function testConstructorWithBasicLimit(): void
    {
        $concurrency = new Concurrency(limit: 10);

        $this->assertSame(10, $concurrency->getLimit());
        $this->assertNull($concurrency->getKey());
        $this->assertNull($concurrency->getScope());
    }

    public function testConstructorWithAllParameters(): void
    {
        $concurrency = new Concurrency(
            limit: 5,
            key: 'event.data.user_id',
            scope: 'env'
        );

        $this->assertSame(5, $concurrency->getLimit());
        $this->assertSame('event.data.user_id', $concurrency->getKey());
        $this->assertSame('env', $concurrency->getScope());
    }

    public function testZeroLimitIsAllowed(): void
    {
        $concurrency = new Concurrency(limit: 0);
        $this->assertSame(0, $concurrency->getLimit());
    }

    public function testNegativeLimitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency limit must be >= 0');

        new Concurrency(limit: -1);
    }

    public function testInvalidScopeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency scope must be one of: fn, env, account');

        new Concurrency(limit: 10, scope: 'invalid');
    }

    public function testValidScopes(): void
    {
        $scopes = ['fn', 'env', 'account'];

        foreach ($scopes as $scope) {
            $concurrency = new Concurrency(limit: 10, scope: $scope);
            $this->assertSame($scope, $concurrency->getScope());
        }
    }

    public function testToArrayWithBasicLimit(): void
    {
        $concurrency = new Concurrency(limit: 10);
        $array = $concurrency->toArray();

        $this->assertSame(['limit' => 10], $array);
    }

    public function testToArrayWithKey(): void
    {
        $concurrency = new Concurrency(
            limit: 5,
            key: 'event.data.user_id'
        );
        $array = $concurrency->toArray();

        $this->assertSame([
            'limit' => 5,
            'key' => 'event.data.user_id',
        ], $array);
    }

    public function testToArrayWithScope(): void
    {
        $concurrency = new Concurrency(
            limit: 100,
            scope: 'account'
        );
        $array = $concurrency->toArray();

        $this->assertSame([
            'limit' => 100,
            'scope' => 'account',
        ], $array);
    }

    public function testToArrayWithAllParameters(): void
    {
        $concurrency = new Concurrency(
            limit: 5,
            key: 'event.data.user_id + "-" + event.data.account_id',
            scope: 'env'
        );
        $array = $concurrency->toArray();

        $this->assertSame([
            'limit' => 5,
            'key' => 'event.data.user_id + "-" + event.data.account_id',
            'scope' => 'env',
        ], $array);
    }

    public function testComplexKeyExpression(): void
    {
        $key = 'event.data.plan == "enterprise" ? event.data.user_id : "default"';
        $concurrency = new Concurrency(
            limit: 10,
            key: $key
        );

        $this->assertSame($key, $concurrency->getKey());

        $array = $concurrency->toArray();
        $this->assertSame($key, $array['key']);
    }
}
