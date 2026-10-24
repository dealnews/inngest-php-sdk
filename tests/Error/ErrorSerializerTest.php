<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Error;

use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for ErrorSerializer (port of JS helpers/errors.test.ts)
 *
 * @package DealNews\Inngest\Tests\Error
 */
#[CoversClass(ErrorSerializer::class)]
#[UsesClass(NonRetriableError::class)]
#[UsesClass(RemoteError::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(ValueObject::class)]
class ErrorSerializerTest extends TestCase {

    /**
     * Any value serializes with the JS-equivalent name and message
     *
     * @param mixed  $subject Value to serialize
     * @param string $name    Expected name
     * @param string $message Expected message
     *
     * @return void
     */
    #[DataProvider('serializeProvider')]
    public function testSerialize(
        mixed $subject,
        string $name,
        string $message
    ): void {
        $serializer = new ErrorSerializer();
        $error      = $serializer->serialize($subject);

        $this->assertSame($name, $error->name);
        $this->assertSame($message, $error->message);
        $this->assertTrue($error->serialized);
        $this->assertNotNull($serializer->isSerialized($error));
    }

    /**
     * Cases for testSerialize; mirrors JS `serializeError` tests
     *
     * @return array<string, array{mixed, string, string}>
     */
    public static function serializeProvider(): array {
        return [
            'string'      => ['test', 'Error', 'test'],
            'number'      => [1, 'Error', '1'],
            'boolean'     => [true, 'Error', 'true'],
            'null'        => [null, 'Error', 'null'],
            'object'      => [['foo' => 'bar'], 'Error', '{"foo":"bar"}'],
            'array'       => [[], 'Error', '[]'],
            'blank error' => [new \Exception(), 'Exception', '{}'],
            'custom'      => [
                new NonRetriableError('test'),
                'NonRetriableError',
                'test',
            ],
            'php error'   => [new \TypeError('bad'), 'TypeError', 'bad'],
            'error-like'  => [
                ['name' => 'Custom', 'message' => 'from array'],
                'Custom',
                'from array',
            ],
            'stdClass'    => [(object)['message' => 'obj'], 'Error', 'obj'],
        ];
    }

    /**
     * An already-serialized error is returned as-is
     *
     * @return void
     */
    public function testExistingSerializedError(): void {
        $serializer = new ErrorSerializer();
        $existing   = $serializer->serialize(new \Exception('test'));

        $this->assertSame($existing, $serializer->serialize($existing));
        $this->assertSame(
            'test',
            $serializer->serialize($existing->toArray())->message
        );
    }

    /**
     * Throwables get a stack trace; code only when non-zero
     *
     * @return void
     */
    public function testThrowableStackAndCode(): void {
        $serializer = new ErrorSerializer();

        $with = $serializer->serialize(new \RuntimeException('coded', 42));
        $none = $serializer->serialize(new \RuntimeException('plain'));

        $this->assertStringStartsWith(
            'RuntimeException: coded in ' . __FILE__,
            $with->stack
        );
        $this->assertStringContainsString("\nStack trace:\n", $with->stack);
        $this->assertSame(42, $with->code);
        $this->assertNull($none->code);
    }

    /**
     * Non-throwable values have an empty stack
     *
     * @return void
     */
    public function testScalarHasEmptyStack(): void {
        $this->assertSame('', (new ErrorSerializer())->serialize('x')->stack);
    }

    /**
     * Error-like arrays keep string/int codes and their stack
     *
     * @return void
     */
    public function testErrorLikeArrayFields(): void {
        $error = (new ErrorSerializer())->serialize([
            'name'    => 'E',
            'message' => 'm',
            'stack'   => 'st',
            'code'    => 'E_X',
        ]);

        $this->assertSame('st', $error->stack);
        $this->assertSame('E_X', $error->code);

        $bad_code = (new ErrorSerializer())->serialize([
            'name' => 'E',
            'code' => ['nope'],
        ]);
        $this->assertNull($bad_code->code);
    }

    /**
     * Causes serialize recursively
     *
     * @return void
     */
    public function testCauseChain(): void {
        $error = (new ErrorSerializer())->serialize(
            new NonRetriableError('outer', new \LogicException('inner'))
        );

        $this->assertInstanceOf(SerializedError::class, $error->cause);
        $this->assertSame('LogicException', $error->cause->name);
        $this->assertSame('inner', $error->cause->message);
    }

    /**
     * Causes stop after five levels, like JS
     *
     * @return void
     */
    public function testCauseDepthLimit(): void {
        $chain = new \Exception('level 7');
        for ($i = 6; $i >= 1; $i--) {
            $chain = new \Exception("level $i", 0, $chain);
        }

        $error = (new ErrorSerializer())->serialize($chain);
        $depth = 0;
        $node  = $error;
        while ($node->cause instanceof SerializedError) {
            $node = $node->cause;
            $depth++;
        }

        $this->assertSame(5, $depth);
        $this->assertSame('level 6', $node->message);
    }

    /**
     * Non-error causes in error-like arrays pass through untouched
     *
     * @return void
     */
    public function testUnknownCausePassesThrough(): void {
        $error = (new ErrorSerializer())->serialize([
            'name'  => 'E',
            'cause' => ['just' => 'data'],
        ]);

        $this->assertSame(['just' => 'data'], $error->cause);

        $scalar = (new ErrorSerializer())->serialize([
            'name'  => 'E',
            'cause' => 'text',
        ]);

        $this->assertSame('text', $scalar->cause);
    }

    /**
     * Error-like causes in arrays are serialized
     *
     * @return void
     */
    public function testErrorLikeCauseSerializes(): void {
        $error = (new ErrorSerializer())->serialize([
            'name'  => 'Outer',
            'cause' => ['name' => 'Inner', 'message' => 'in'],
        ]);

        $this->assertInstanceOf(SerializedError::class, $error->cause);
        $this->assertSame('Inner', $error->cause->name);
    }

    /**
     * isSerialized() recognizes every serialized form
     *
     * @return void
     */
    public function testIsSerialized(): void {
        $serializer = new ErrorSerializer();
        $error      = $serializer->serialize(new \Exception('x'));

        $this->assertSame($error, $serializer->isSerialized($error));
        $this->assertNotNull($serializer->isSerialized($error->toArray()));
        $this->assertNotNull($serializer->isSerialized($error->toJson()));
        $this->assertNull($serializer->isSerialized(['name' => 'x']));
        $this->assertNull($serializer->isSerialized('not json'));
        $this->assertNull(
            $serializer->isSerialized('{"__serialized":true,"name":"x"}')
        );
        $this->assertNull($serializer->isSerialized(5));
    }

    /**
     * Round trip: NonRetriableError comes back as NonRetriableError
     *
     * @return void
     */
    public function testRoundTripNonRetriable(): void {
        $serializer = new ErrorSerializer();
        $error      = $serializer->deserialize(
            $serializer->serialize(new NonRetriableError('boom'))
        );

        $this->assertInstanceOf(NonRetriableError::class, $error);
        $this->assertSame('boom', $error->getMessage());
    }

    /**
     * Round trip: other errors come back as RemoteError with their name
     *
     * @return void
     */
    public function testRoundTripOtherErrors(): void {
        $serializer = new ErrorSerializer();
        $error      = $serializer->deserialize(
            $serializer->serialize(new \TypeError('bad'))
        );

        $this->assertInstanceOf(RemoteError::class, $error);
        $this->assertSame('TypeError', $error->getErrorName());
        $this->assertSame('bad', $error->getMessage());
        $this->assertStringStartsWith(
            'TypeError: bad',
            $error->getErrorStack()
        );
    }

    /**
     * Round trip keeps the cause
     *
     * @return void
     */
    public function testRoundTripCause(): void {
        $serializer = new ErrorSerializer();
        $error      = $serializer->deserialize(
            $serializer->serialize(
                new NonRetriableError('outer', new \Exception('inner'))
            )
        );

        $this->assertInstanceOf(RemoteError::class, $error->getPrevious());
        $this->assertSame('inner', $error->getPrevious()->getMessage());
    }

    /**
     * Round trip keeps a non-error cause as the raw cause
     *
     * @return void
     */
    public function testRoundTripRawCause(): void {
        $error = (new ErrorSerializer())->deserialize([
            'name'    => 'Error',
            'message' => 'm',
            'cause'   => ['data' => 1],
        ]);

        $this->assertInstanceOf(RemoteError::class, $error);
        $this->assertNull($error->getPrevious());
        $this->assertSame(['data' => 1], $error->getRawCause());
    }

    /**
     * A stringified serialized error isn't double-wrapped
     *
     * @return void
     */
    public function testStringifiedSerializedError(): void {
        $serializer = new ErrorSerializer();
        $json       = $serializer->serialize(new \Exception('stringified'))
            ->toJson();

        $error = $serializer->deserialize($serializer->serialize($json));

        $this->assertSame('Exception', $error->getErrorName());
        $this->assertSame('stringified', $error->getMessage());
    }

    /**
     * Round trip keeps the code
     *
     * @return void
     */
    public function testRoundTripCode(): void {
        $serializer = new ErrorSerializer();
        $error      = $serializer->deserialize(
            $serializer->serialize(new \RuntimeException('with code', 7))
        );

        $this->assertSame(7, $error->getErrorCode());
        $this->assertSame('with code', $error->getMessage());
    }

    /**
     * Data missing name or message can't be rebuilt
     *
     * @return void
     */
    public function testDeserializeUnknown(): void {
        $error = (new ErrorSerializer())->deserialize(['message' => 'only']);

        $this->assertInstanceOf(RemoteError::class, $error);
        $this->assertSame(
            'Unknown error; could not reserialize',
            $error->getMessage()
        );
        $this->assertNull($error->getErrorStack());
    }

    /**
     * Bad field types fall back to safe values
     *
     * @return void
     */
    public function testDeserializeBadFieldTypes(): void {
        $error = (new ErrorSerializer())->deserialize([
            'name'    => 5,
            'message' => ['x'],
            'stack'   => false,
            'code'    => 1.5,
        ]);

        $this->assertSame('Error', $error->getErrorName());
        $this->assertSame('', $error->getMessage());
        $this->assertNull($error->getErrorStack());
        $this->assertNull($error->getErrorCode());
    }

    /**
     * deserializeCause() passes non-errors through
     *
     * @return void
     */
    public function testDeserializeCause(): void {
        $serializer = new ErrorSerializer();

        $this->assertSame('text', $serializer->deserializeCause('text'));
        $this->assertInstanceOf(
            RemoteError::class,
            $serializer->deserializeCause(['name' => 'E', 'message' => 'm'])
        );
    }

    /**
     * Rebuilt remote errors serialize back to the same wire data
     *
     * @return void
     */
    public function testRemoteErrorReserializes(): void {
        $serializer = new ErrorSerializer();
        $remote     = new RemoteError(
            'TypeError',
            'bad',
            'original stack',
            'E1',
            null,
            ['raw' => 1]
        );

        $error = $serializer->serialize($remote);

        $this->assertSame('TypeError', $error->name);
        $this->assertSame('original stack', $error->stack);
        $this->assertSame('E1', $error->code);
        $this->assertSame(['raw' => 1], $error->cause);

        $no_stack = $serializer->serialize(new RemoteError('E', 'm'));
        $this->assertSame('', $no_stack->stack);
    }

    /**
     * Values JSON can't encode still produce a message
     *
     * @return void
     */
    public function testUnencodableValue(): void {
        $error = (new ErrorSerializer())->serialize(["\xB1\x31"]);

        $this->assertSame(
            'Unknown error; error serialization could not find a message.',
            $error->message
        );
    }
}
