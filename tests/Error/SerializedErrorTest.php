<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Error;

use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the SerializedError wire format
 *
 * @package DealNews\Inngest\Tests\Error
 */
#[CoversClass(SerializedError::class)]
class SerializedErrorTest extends TestCase {

    /**
     * Key order and marker match JS output; code/cause omitted when unset
     *
     * @return void
     */
    public function testMinimalJson(): void {
        $error          = new SerializedError();
        $error->message = 'boom';

        $this->assertSame(
            '{"name":"Error","message":"boom","stack":"","__serialized":true}',
            $error->toJson()
        );
    }

    /**
     * Code and nested cause are included when set
     *
     * @return void
     */
    public function testFullJson(): void {
        $cause          = new SerializedError();
        $cause->message = 'inner';

        $error          = new SerializedError();
        $error->name    = 'NonRetriableError';
        $error->message = 'outer';
        $error->stack   = 'trace';
        $error->code    = 'E_TEST';
        $error->cause   = $cause;

        $this->assertSame(
            '{"name":"NonRetriableError","message":"outer","stack":"trace",' .
            '"code":"E_TEST","cause":{"name":"Error","message":"inner",' .
            '"stack":"","__serialized":true},"__serialized":true}',
            $error->toJson()
        );
    }

    /**
     * Wire data hydrates, including the marker
     *
     * @return void
     */
    public function testFromArray(): void {
        $error = SerializedError::fromArray([
            'name'         => 'TypeError',
            'message'      => 'bad',
            'stack'        => 's',
            '__serialized' => true,
        ]);

        $this->assertSame('TypeError', $error->name);
        $this->assertTrue($error->serialized);
    }
}
