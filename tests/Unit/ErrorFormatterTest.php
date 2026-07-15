<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Error\ErrorFormatter;
use DealNews\Inngest\Error\StepError;
use PHPUnit\Framework\TestCase;

class ErrorFormatterTest extends TestCase
{
    public function testFormatWithoutPrevious(): void
    {
        $e = new \RuntimeException('boom');

        $error = ErrorFormatter::format($e);

        $this->assertSame(\RuntimeException::class, $error['name']);
        $this->assertSame('boom', $error['message']);
        $this->assertArrayHasKey('stack', $error);
        $this->assertArrayNotHasKey('cause', $error);
    }

    public function testFormatIncludesPreviousExceptionChain(): void
    {
        $root = new \LogicException('root cause');
        $middle = new \RuntimeException('middle failure', 0, $root);
        $top = new \RuntimeException('top failure', 0, $middle);

        $error = ErrorFormatter::format($top);

        $this->assertSame('top failure', $error['message']);
        $this->assertArrayHasKey('cause', $error);

        $this->assertSame('middle failure', $error['cause']['message']);
        $this->assertArrayHasKey('cause', $error['cause']);

        $this->assertSame(\LogicException::class, $error['cause']['cause']['name']);
        $this->assertSame('root cause', $error['cause']['cause']['message']);
        $this->assertArrayNotHasKey('cause', $error['cause']['cause']);
    }

    public function testFormatPreservesStepErrorRemoteDetailsInsteadOfLocalTrace(): void
    {
        $step_error = new StepError(
            'remote step failed',
            'RemoteExceptionClass',
            'remote stack trace...'
        );

        $error = ErrorFormatter::format($step_error);

        $this->assertSame('RemoteExceptionClass', $error['name']);
        $this->assertSame('remote step failed', $error['message']);
        $this->assertSame('remote stack trace...', $error['stack']);
    }

    public function testFormatPreservesStepErrorCauseChain(): void
    {
        $root = new \LogicException('root cause');
        $step_error = new StepError(
            'remote step failed',
            'RemoteExceptionClass',
            'remote stack trace...',
            0,
            $root
        );

        $error = ErrorFormatter::format($step_error);

        $this->assertSame('RemoteExceptionClass', $error['name']);
        $this->assertArrayHasKey('cause', $error);
        $this->assertSame(\LogicException::class, $error['cause']['name']);
        $this->assertSame('root cause', $error['cause']['message']);
    }

    public function testFormatTruncatesCauseChainAtMaxDepth(): void
    {
        $e = new \RuntimeException('deepest');
        for ($i = 0; $i < ErrorFormatter::MAX_CAUSE_DEPTH + 5; $i++) {
            $e = new \RuntimeException("level {$i}", 0, $e);
        }

        $error = ErrorFormatter::format($e);

        $depth = 0;
        while (isset($error['cause'])) {
            $error = $error['cause'];
            $depth++;
        }

        $this->assertSame(ErrorFormatter::MAX_CAUSE_DEPTH, $depth);
    }

    public function testFormatTruncatesCauseChainMixedWithStepErrors(): void
    {
        $e = new \RuntimeException('deepest');
        for ($i = 0; $i < ErrorFormatter::MAX_CAUSE_DEPTH + 5; $i++) {
            $e = ($i % 2 === 0)
                ? new StepError("level {$i}", "Step{$i}", null, 0, $e)
                : new \RuntimeException("level {$i}", 0, $e);
        }

        $error = ErrorFormatter::format($e);

        $depth = 0;
        while (isset($error['cause'])) {
            $error = $error['cause'];
            $depth++;
        }

        $this->assertSame(ErrorFormatter::MAX_CAUSE_DEPTH, $depth);
    }
}
