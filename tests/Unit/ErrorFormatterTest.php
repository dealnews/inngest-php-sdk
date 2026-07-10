<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Error\ErrorFormatter;
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
}
