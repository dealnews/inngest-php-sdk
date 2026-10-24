<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\ValueObject;

use DealNews\Inngest\Tests\mock\SampleChild;
use DealNews\Inngest\Tests\mock\SampleParent;
use DealNews\Inngest\Tests\mock\SamplePromoted;
use DealNews\Inngest\Tests\mock\SampleRequired;
use DealNews\Inngest\Tests\mock\SampleStatus;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the ValueObject base class
 *
 * @package DealNews\Inngest\Tests\ValueObject
 */
#[CoversClass(ValueObject::class)]
class ValueObjectTest extends TestCase {

    /**
     * Defaults export with wire keys, nulls omitted, KEEP_NULL kept
     *
     * @return void
     */
    public function testToArrayDefaults(): void {
        $vo = new SampleParent();

        $this->assertSame(
            [
                'appName'  => '',
                'env'      => null,
                'attempt'  => 0,
                'children' => [],
                'steps'    => [],
            ],
            $vo->toArray()
        );
    }

    /**
     * Static, protected, and uninitialized properties are never exported
     *
     * @return void
     */
    public function testToArraySkipsNonPublicStaticAndUninitialized(): void {
        $data = (new SampleParent())->toArray();

        $this->assertArrayNotHasKey('ignored', $data);
        $this->assertArrayNotHasKey('internal', $data);
        $this->assertArrayNotHasKey('no_default', $data);
    }

    /**
     * Once set, a property without a default is exported
     *
     * @return void
     */
    public function testToArrayIncludesInitializedNoDefault(): void {
        $vo             = new SampleParent();
        $vo->no_default = 'set';

        $this->assertSame('set', $vo->toArray()['no_default']);
    }

    /**
     * Nested objects, collections, and enums export recursively
     *
     * @return void
     */
    public function testToArrayNested(): void {
        $child             = new SampleChild();
        $child->child_name = 'a';
        $child->count      = 2;

        $other             = new SampleChild();
        $other->child_name = 'b';

        $vo           = new SampleParent();
        $vo->status   = SampleStatus::Active;
        $vo->child    = $child;
        $vo->children = [$child, $other];
        $vo->steps    = ['step' => $other];

        $data = $vo->toArray();

        $this->assertSame('active', $data['status']);
        $this->assertSame(['childName' => 'a', 'count' => 2], $data['child']);
        $this->assertSame(
            [
                ['childName' => 'a', 'count' => 2],
                ['childName' => 'b'],
            ],
            $data['children']
        );
        $this->assertSame(['step' => ['childName' => 'b']], $data['steps']);
    }

    /**
     * Mixed data passes through, including nested JsonSerializable values
     *
     * @return void
     */
    public function testToArrayPassesThroughData(): void {
        $child             = new SampleChild();
        $child->child_name = 'inner';

        $vo       = new SampleParent();
        $vo->data = ['list' => [1, 2], 'vo' => $child];

        $this->assertSame(
            ['list' => [1, 2], 'vo' => ['childName' => 'inner']],
            $vo->toArray()['data']
        );
    }

    /**
     * Other JsonSerializable objects export via jsonSerialize()
     *
     * @return void
     */
    public function testToArrayUsesJsonSerializable(): void {
        $vo       = new SampleParent();
        $vo->data = new class() implements \JsonSerializable {
            /**
             * @return array<string, int>
             */
            public function jsonSerialize(): array {
                return ['serialized' => 1];
            }
        };

        $this->assertSame(['serialized' => 1], $vo->toArray()['data']);
    }

    /**
     * Objects that can't be exported fail loudly
     *
     * @return void
     */
    public function testToArrayThrowsOnUnexportableObject(): void {
        $vo       = new SampleParent();
        $vo->data = new \DateTimeImmutable();

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1002);
        $vo->toArray();
    }

    /**
     * JSON keeps empty OBJECT_KEYS as {} and other empty arrays as []
     *
     * @return void
     */
    public function testToJsonEmptyObjectsAndLists(): void {
        $this->assertSame(
            '{"appName":"","env":null,"attempt":0,"children":[],"steps":{}}',
            (new SampleParent())->toJson()
        );
    }

    /**
     * JSON output doesn't escape slashes or unicode, like JS
     *
     * @return void
     */
    public function testToJsonUnescaped(): void {
        $vo           = new SampleParent();
        $vo->app_name = 'https://example.com/ü';

        $this->assertStringContainsString(
            '"appName":"https://example.com/ü"',
            $vo->toJson()
        );
    }

    /**
     * json_encode() uses the same output as toJson()
     *
     * @return void
     */
    public function testJsonEncodeMatchesToJson(): void {
        $vo = new SampleParent();

        $this->assertSame(
            $vo->toJson(),
            json_encode($vo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * fromArray() hydrates every supported kind of property
     *
     * @return void
     */
    public function testFromArrayHydrates(): void {
        $vo = SampleParent::fromArray([
            'appName'  => 'my-app',
            'version'  => '1.0',
            'attempt'  => 3,
            'status'   => 'disabled',
            'child'    => ['childName' => 'c', 'count' => 1],
            'children' => [['childName' => 'x'], ['child_name' => 'y']],
            'steps'    => ['step' => ['childName' => 'z']],
            'data'     => ['anything' => true],
        ]);

        $this->assertSame('my-app', $vo->app_name);
        $this->assertSame('1.0', $vo->version);
        $this->assertSame(3, $vo->attempt);
        $this->assertSame(SampleStatus::Disabled, $vo->status);
        $this->assertInstanceOf(SampleChild::class, $vo->child);
        $this->assertSame('c', $vo->child->child_name);
        $this->assertSame(1, $vo->child->count);
        $this->assertCount(2, $vo->children);
        $this->assertSame('x', $vo->children[0]->child_name);
        $this->assertSame('y', $vo->children[1]->child_name);
        $this->assertSame('z', $vo->steps['step']->child_name);
        $this->assertSame(['anything' => true], $vo->data);
    }

    /**
     * Collection items that are already objects are kept as-is
     *
     * @return void
     */
    public function testFromArrayKeepsHydratedCollectionItems(): void {
        $child = new SampleChild();

        $vo = SampleParent::fromArray(['children' => [$child]]);

        $this->assertSame($child, $vo->children[0]);
    }

    /**
     * Promoted constructor properties get their defaults when hydrating
     *
     * @return void
     */
    public function testFromArrayUsesOptionalConstructor(): void {
        $empty = SamplePromoted::fromArray([]);
        $set   = SamplePromoted::fromArray(['size' => 3]);

        $this->assertSame('default', $empty->label);
        $this->assertNull($empty->size);
        $this->assertSame(['label' => 'default', 'size' => 3], $set->toArray());
    }

    /**
     * A constructor with required parameters is skipped
     *
     * @return void
     */
    public function testFromArraySkipsRequiredConstructor(): void {
        $this->assertSame('unset', SampleRequired::fromArray([])->name);
        $this->assertSame(
            'raw',
            SampleRequired::fromArray(['name' => 'raw'])->name
        );
    }

    /**
     * PHP property names are accepted as input keys too
     *
     * @return void
     */
    public function testFromArrayAcceptsPropertyNames(): void {
        $vo = SampleParent::fromArray(['app_name' => 'by-property']);

        $this->assertSame('by-property', $vo->app_name);
    }

    /**
     * Unknown, static, and protected keys are ignored
     *
     * @return void
     */
    public function testFromArrayIgnoresUnknownKeys(): void {
        $vo = SampleParent::fromArray([
            'nope'     => 1,
            'ignored'  => 'changed',
            'internal' => 'changed',
        ]);

        $this->assertSame('static', SampleParent::$ignored);
        $this->assertSame('', $vo->app_name);
    }

    /**
     * Null for a non-nullable property leaves the default in place
     *
     * @return void
     */
    public function testFromArrayNullOnNonNullableKeepsDefault(): void {
        $vo = SampleParent::fromArray(['attempt' => null, 'version' => null]);

        $this->assertSame(0, $vo->attempt);
        $this->assertNull($vo->version);
    }

    /**
     * Round trip through JSON returns the same wire output
     *
     * @return void
     */
    public function testRoundTrip(): void {
        $json = '{"appName":"a","version":"2","env":"prod","attempt":1,' .
                '"status":"active","child":{"childName":"c"},' .
                '"children":[{"childName":"d","count":4}],' .
                '"steps":{"s":{"childName":"e"}},"data":{"k":"v"}}';

        $this->assertSame($json, SampleParent::fromJson($json)->toJson());
    }

    /**
     * Unknown enum values are rejected
     *
     * @return void
     */
    public function testFromArrayRejectsUnknownEnum(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1004);
        SampleParent::fromArray(['status' => 'bogus']);
    }

    /**
     * Wrong scalar types are rejected with a clear error
     *
     * @return void
     */
    public function testFromArrayRejectsWrongType(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1003);
        SampleParent::fromArray(['attempt' => 'not-an-int']);
    }

    /**
     * fromJson() requires a JSON object
     *
     * @return void
     */
    public function testFromJsonRejectsNonObject(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1001);
        SampleParent::fromJson('"just a string"');
    }

    /**
     * fromJson() rejects invalid JSON
     *
     * @return void
     */
    public function testFromJsonRejectsInvalidJson(): void {
        $this->expectException(\JsonException::class);
        SampleParent::fromJson('{bad');
    }
}
