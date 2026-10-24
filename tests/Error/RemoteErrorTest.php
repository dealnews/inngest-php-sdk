<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Error;

use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for RemoteError
 *
 * @package DealNews\Inngest\Tests\Error
 */
#[CoversClass(RemoteError::class)]
class RemoteErrorTest extends TestCase {

    /**
     * Every original property is available
     *
     * @return void
     */
    public function testGetters(): void {
        $previous = new \LogicException('cause');
        $error    = new RemoteError(
            'TypeError',
            'bad type',
            'TypeError: bad type\\n    at x',
            'E_TYPE',
            $previous,
            ['raw' => true]
        );

        $this->assertSame('TypeError', $error->getErrorName());
        $this->assertSame('bad type', $error->getMessage());
        $this->assertSame(
            'TypeError: bad type\\n    at x',
            $error->getErrorStack()
        );
        $this->assertSame('E_TYPE', $error->getErrorCode());
        $this->assertSame($previous, $error->getPrevious());
        $this->assertSame(['raw' => true], $error->getRawCause());
        $this->assertSame(0, $error->getCode());
    }

    /**
     * Optional properties default to null
     *
     * @return void
     */
    public function testDefaults(): void {
        $error = new RemoteError('Error', 'msg');

        $this->assertNull($error->getErrorStack());
        $this->assertNull($error->getErrorCode());
        $this->assertNull($error->getPrevious());
        $this->assertNull($error->getRawCause());
    }
}
