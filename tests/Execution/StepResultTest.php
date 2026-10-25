<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Execution\StepResult;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepResult
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(StepResult::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(RemoteError::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(StepError::class)]
#[UsesClass(ValueObject::class)]
class StepResultTest extends TestCase {

    /**
     * Data comes back from unwrap()
     *
     * @return void
     */
    public function testData(): void {
        $result = StepResult::data(['a' => 1]);

        $this->assertFalse($result->wrap_only);
        $this->assertNull($result->error);
        $this->assertSame(['a' => 1], $result->unwrap());
    }

    /**
     * Errors are thrown by unwrap()
     *
     * @return void
     */
    public function testError(): void {
        $error  = new StepError('A', ['name' => 'Error', 'message' => 'no']);
        $result = StepResult::error($error);

        $this->assertSame($error, $result->error);
        try {
            $result->unwrap();
            $this->fail('Expected the step error');
        } catch (StepError $e) {
            $this->assertSame($error, $e);
        }
    }

    /**
     * A wrap-only result carries nothing
     *
     * @return void
     */
    public function testWrapOnly(): void {
        $result = StepResult::wrapOnly();

        $this->assertTrue($result->wrap_only);
        $this->assertNull($result->unwrap());
    }
}
