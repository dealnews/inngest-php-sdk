<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Middleware\WrapRequestArgs;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for WrapRequestArgs
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(WrapRequestArgs::class)]
#[UsesClass(Request::class)]
#[UsesClass(Response::class)]
class WrapRequestArgsTest extends TestCase {

    /**
     * next() runs the rest of the chain
     *
     * @return void
     */
    public function testNext(): void {
        $request  = new Request('POST', 'https://example.com/api/inngest');
        $response = new Response(200);

        $args = new WrapRequestArgs(
            $request,
            null,
            'run-1',
            static fn () => $response
        );

        $this->assertSame($request, $args->request);
        $this->assertNull($args->function);
        $this->assertSame('run-1', $args->run_id);
        $this->assertSame($response, $args->next());
    }
}
