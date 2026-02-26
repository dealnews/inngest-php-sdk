<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\Singleton;
use PHPUnit\Framework\TestCase;

class SingletonTest extends TestCase
{
    public function testConstructorWithModeOnly(): void
    {
        $singleton = new Singleton(mode: 'skip');

        $this->assertSame('skip', $singleton->getMode());
        $this->assertNull($singleton->getKey());
    }

    public function testConstructorWithAllParameters(): void
    {
        $singleton = new Singleton(
            mode: 'cancel',
            key: 'event.data.user_id'
        );

        $this->assertSame('cancel', $singleton->getMode());
        $this->assertSame('event.data.user_id', $singleton->getKey());
    }

    public function testSkipMode(): void
    {
        $singleton = new Singleton(mode: 'skip');
        $this->assertSame('skip', $singleton->getMode());
    }

    public function testCancelMode(): void
    {
        $singleton = new Singleton(mode: 'cancel');
        $this->assertSame('cancel', $singleton->getMode());
    }

    public function testEmptyModeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Singleton mode cannot be empty');

        new Singleton(mode: '');
    }

    public function testWhitespaceOnlyModeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Singleton mode cannot be empty');

        new Singleton(mode: '   ');
    }

    public function testInvalidModeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Singleton mode must be either "skip" or "cancel", got: "invalid"'
        );

        new Singleton(mode: 'invalid');
    }

    public function testInvalidModeCaseSensitive(): void
    {
        $invalid_modes = ['Skip', 'SKIP', 'Cancel', 'CANCEL', 'SKIP_MODE'];

        foreach ($invalid_modes as $mode) {
            try {
                new Singleton(mode: $mode);
                $this->fail("Expected exception for mode: {$mode}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'Singleton mode must be either "skip" or "cancel"',
                    $e->getMessage()
                );
            }
        }
    }

    public function testWithSimpleKey(): void
    {
        $singleton = new Singleton(
            mode: 'skip',
            key: 'event.data.user_id'
        );

        $this->assertSame('event.data.user_id', $singleton->getKey());
    }

    public function testWithComplexKey(): void
    {
        $key = 'event.data.customer_id + "-" + event.data.region';
        $singleton = new Singleton(
            mode: 'skip',
            key: $key
        );

        $this->assertSame($key, $singleton->getKey());
    }

    public function testToArrayWithModeOnly(): void
    {
        $singleton = new Singleton(mode: 'skip');
        $array = $singleton->toArray();

        $this->assertSame(['mode' => 'skip'], $array);
    }

    public function testToArrayWithKey(): void
    {
        $singleton = new Singleton(
            mode: 'cancel',
            key: 'event.data.user_id'
        );
        $array = $singleton->toArray();

        $this->assertSame([
            'mode' => 'cancel',
            'key'  => 'event.data.user_id',
        ], $array);
    }

    public function testToArrayWithAllParameters(): void
    {
        $singleton = new Singleton(
            mode: 'skip',
            key: 'event.data.customer_id + "-" + event.data.region'
        );
        $array = $singleton->toArray();

        $this->assertSame([
            'mode' => 'skip',
            'key'  => 'event.data.customer_id + "-" + event.data.region',
        ], $array);
    }

    public function testMultipleKeyExpressions(): void
    {
        $keys = [
            'event.data.user_id',
            'event.data.customer_id + "-" + event.data.account_id',
            'event.user.email',
            'event.data.region',
            'event.data.tenant_id',
        ];

        foreach ($keys as $key) {
            $singleton = new Singleton(mode: 'skip', key: $key);
            $this->assertSame($key, $singleton->getKey());

            $array = $singleton->toArray();
            $this->assertSame($key, $array['key']);
        }
    }

    public function testConditionalKeyExpression(): void
    {
        $key = 'event.data.plan == "enterprise" ? '.
               'event.data.user_id : "default"';
        $singleton = new Singleton(mode: 'cancel', key: $key);

        $this->assertSame($key, $singleton->getKey());

        $array = $singleton->toArray();
        $this->assertSame($key, $array['key']);
    }

    public function testSkipModeWithKey(): void
    {
        $singleton = new Singleton(
            mode: 'skip',
            key: 'event.data.user_id'
        );

        $this->assertSame('skip', $singleton->getMode());
        $this->assertSame('event.data.user_id', $singleton->getKey());

        $array = $singleton->toArray();
        $this->assertSame('skip', $array['mode']);
        $this->assertSame('event.data.user_id', $array['key']);
    }

    public function testCancelModeWithKey(): void
    {
        $singleton = new Singleton(
            mode: 'cancel',
            key: 'event.data.session_id'
        );

        $this->assertSame('cancel', $singleton->getMode());
        $this->assertSame('event.data.session_id', $singleton->getKey());

        $array = $singleton->toArray();
        $this->assertSame('cancel', $array['mode']);
        $this->assertSame('event.data.session_id', $array['key']);
    }

    public function testNullKey(): void
    {
        $singleton = new Singleton(mode: 'skip', key: null);
        $this->assertNull($singleton->getKey());

        $array = $singleton->toArray();
        $this->assertArrayNotHasKey('key', $array);
    }

    public function testVariousModeAndKeyCombinat(): void
    {
        $combinations = [
            ['mode' => 'skip', 'key' => null],
            ['mode' => 'skip', 'key' => 'event.data.user_id'],
            ['mode' => 'cancel', 'key' => null],
            ['mode' => 'cancel', 'key' => 'event.data.user_id'],
        ];

        foreach ($combinations as $combo) {
            $singleton = new Singleton(
                mode: $combo['mode'],
                key: $combo['key']
            );

            $this->assertSame($combo['mode'], $singleton->getMode());
            $this->assertSame($combo['key'], $singleton->getKey());

            $array = $singleton->toArray();
            $this->assertSame($combo['mode'], $array['mode']);

            if ($combo['key'] !== null) {
                $this->assertSame($combo['key'], $array['key']);
            } else {
                $this->assertArrayNotHasKey('key', $array);
            }
        }
    }
}
