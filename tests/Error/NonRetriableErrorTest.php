<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Error;

use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for NonRetriableError
 *
 * @package DealNews\Inngest\Tests\Error
 */
#[CoversClass(NonRetriableError::class)]
class NonRetriableErrorTest extends TestCase {

    /**
     * Message and cause are kept
     *
     * @return void
     */
    public function testMessageAndCause(): void {
        $cause = new \LogicException('inner');
        $error = new NonRetriableError('outer', $cause);

        $this->assertSame('outer', $error->getMessage());
        $this->assertSame($cause, $error->getPrevious());
        $this->assertSame(0, $error->getCode());
    }

    /**
     * All arguments are optional
     *
     * @return void
     */
    public function testDefaults(): void {
        $error = new NonRetriableError();

        $this->assertSame('', $error->getMessage());
        $this->assertNull($error->getPrevious());
    }
}
