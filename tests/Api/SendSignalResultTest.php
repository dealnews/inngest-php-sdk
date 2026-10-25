<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Api;

use DealNews\Inngest\Api\SendSignalResult;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for SendSignalResult
 *
 * @package DealNews\Inngest\Tests\Api
 */
#[CoversClass(SendSignalResult::class)]
#[UsesClass(ValueObject::class)]
class SendSignalResultTest extends TestCase {

    /**
     * Wire shape matches JS `{runId}`; no run means `{}`
     *
     * @return void
     */
    public function testWireShape(): void {
        $this->assertSame(
            '{"runId":"run-2"}',
            (new SendSignalResult('run-2'))->toJson()
        );
        $this->assertSame('{}', (new SendSignalResult())->toJson());
        $this->assertSame(
            'run-3',
            SendSignalResult::fromArray(['runId' => 'run-3'])->run_id
        );
    }
}
