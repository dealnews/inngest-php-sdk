<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Error;

use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepError
 *
 * @package DealNews\Inngest\Tests\Error
 */
#[CoversClass(StepError::class)]
#[UsesClass(ErrorSerializer::class)]
class StepErrorTest extends TestCase {

    /**
     * A full serialized error populates every field
     *
     * @return void
     */
    public function testFromSerializedError(): void {
        $error = new StepError('charge-card', [
            'name'         => 'TypeError',
            'message'      => 'card declined',
            'stack'        => 'TypeError: card declined',
            '__serialized' => true,
        ]);

        $this->assertInstanceOf(RemoteError::class, $error);
        $this->assertSame('charge-card', $error->getStepId());
        $this->assertSame('TypeError', $error->getErrorName());
        $this->assertSame('card declined', $error->getMessage());
        $this->assertSame('TypeError: card declined', $error->getErrorStack());
    }

    /**
     * Normalization matches the JS jsonErrorSchema fallbacks
     *
     * @param mixed       $input   Serialized error data
     * @param string      $name    Expected name
     * @param string      $message Expected message
     * @param string|null $stack   Expected stack
     *
     * @return void
     */
    #[DataProvider('normalizeProvider')]
    public function testNormalization(
        mixed $input,
        string $name,
        string $message,
        ?string $stack
    ): void {
        $error = new StepError('step', $input);

        $this->assertSame($name, $error->getErrorName());
        $this->assertSame($message, $error->getMessage());
        $this->assertSame($stack, $error->getErrorStack());
    }

    /**
     * Cases for testNormalization
     *
     * @return array<string, array{mixed, string, string, ?string}>
     */
    public static function normalizeProvider(): array {
        return [
            'not an array'   => ['oops', 'Error', 'Unknown error', null],
            'empty'          => [[], 'Error', 'Unknown error', null],
            'legacy error'   => [
                ['error' => 'old style'],
                'Error',
                'old style',
                null,
            ],
            'trims'          => [
                ['name' => ' E ', 'message' => ' m ', 'stack' => ' s '],
                'E',
                'm',
                's',
            ],
            'blank name'      => [
                ['name' => '  ', 'message' => 'm'],
                'Error',
                'm',
                null,
            ],
            'bad type resets' => [
                ['name' => 5, 'message' => 'kept?'],
                'Error',
                'Unknown error',
                null,
            ],
        ];
    }

    /**
     * Error causes become the previous exception
     *
     * @return void
     */
    public function testErrorCause(): void {
        $error = new StepError('step', [
            'name'    => 'Error',
            'message' => 'outer',
            'cause'   => ['name' => 'Error', 'message' => 'inner'],
        ]);

        $this->assertInstanceOf(RemoteError::class, $error->getPrevious());
        $this->assertSame('inner', $error->getPrevious()->getMessage());
        $this->assertNull($error->getRawCause());
    }

    /**
     * Non-error causes are kept as the raw cause
     *
     * @return void
     */
    public function testRawCause(): void {
        $error = new StepError('step', [
            'message' => 'outer',
            'cause'   => 'just text',
        ]);

        $this->assertNull($error->getPrevious());
        $this->assertSame('just text', $error->getRawCause());
    }
}
