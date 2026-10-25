<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions;

use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\Options\Cancellation;
use DealNews\Inngest\Functions\Options\Concurrency;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for FunctionOptions validation
 *
 * @package DealNews\Inngest\Tests\Functions
 */
#[CoversClass(FunctionOptions::class)]
#[UsesClass(Cancellation::class)]
#[UsesClass(Concurrency::class)]
#[UsesClass(EventTrigger::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(ValueObject::class)]
class FunctionOptionsTest extends TestCase {

    /**
     * Valid options are kept as given
     *
     * @return void
     */
    public function testValidOptions(): void {
        $trigger = new EventTrigger('foo');
        $options = new FunctionOptions(
            id: 'fn',
            triggers: [$trigger],
            concurrency: [new Concurrency(1), new Concurrency(2)],
            cancel_on: [new Cancellation('bar')],
            retries: 20,
            on_failure: 'strtoupper'
        );

        $this->assertSame('fn', $options->id);
        $this->assertSame([$trigger], $options->triggers);
        $this->assertSame(20, $options->retries);
        $this->assertSame('X', ($options->on_failure)('x'));
    }

    /**
     * Defaults: nothing optional is set
     *
     * @return void
     */
    public function testDefaults(): void {
        $options = new FunctionOptions(id: 'fn');

        $this->assertNull($options->name);
        $this->assertNull($options->description);
        $this->assertSame([], $options->triggers);
        $this->assertNull($options->concurrency);
        $this->assertSame([], $options->cancel_on);
        $this->assertNull($options->retries);
        $this->assertNull($options->on_failure);
        $this->assertSame([], $options->middleware);
    }

    /**
     * Retries at the bounds are allowed
     *
     * @return void
     */
    public function testRetryBounds(): void {
        $this->assertSame(
            0,
            (new FunctionOptions(id: 'a', retries: 0))->retries
        );
        $this->assertSame(
            20,
            (new FunctionOptions(id: 'a', retries: 20))->retries
        );
    }

    /**
     * Invalid options throw with specific codes
     *
     * @param \Closure $build Builds the options
     * @param int      $code  Expected code
     *
     * @return void
     */
    #[DataProvider('invalidProvider')]
    public function testInvalid(\Closure $build, int $code): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode($code);
        $build();
    }

    /**
     * Cases for testInvalid
     *
     * @return array<string, array{\Closure, int}>
     */
    public static function invalidProvider(): array {
        return [
            'empty id'            => [
                fn () => new FunctionOptions(id: ' '),
                9001,
            ],
            'negative retries'    => [
                fn () => new FunctionOptions(id: 'a', retries: -1),
                9002,
            ],
            'too many retries'    => [
                fn () => new FunctionOptions(id: 'a', retries: 21),
                9002,
            ],
            'empty concurrency'   => [
                fn () => new FunctionOptions(id: 'a', concurrency: []),
                9003,
            ],
            'three concurrencies' => [
                fn () => new FunctionOptions(id: 'a', concurrency: [
                    new Concurrency(1),
                    new Concurrency(2),
                    new Concurrency(3),
                ]),
                9003,
            ],
            'concurrency ints'    => [
                fn () => new FunctionOptions(id: 'a', concurrency: [1, 2]),
                9004,
            ],
            'string trigger'      => [
                fn () => new FunctionOptions(id: 'a', triggers: ['foo']),
                9005,
            ],
            'middleware not a class' => [
                fn () => new FunctionOptions(
                    id: 'a',
                    middleware: [\stdClass::class]
                ),
                10002,
            ],
            'keyed triggers'      => [
                fn () => new FunctionOptions(
                    id: 'a',
                    triggers: ['x' => new EventTrigger('foo')]
                ),
                9005,
            ],
            'bad cancel_on'       => [
                fn () => new FunctionOptions(id: 'a', cancel_on: ['bar']),
                9006,
            ],
        ];
    }
}
