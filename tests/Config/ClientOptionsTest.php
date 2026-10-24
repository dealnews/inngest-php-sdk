<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Config;

use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for ClientOptions
 *
 * @package DealNews\Inngest\Tests\Config
 */
#[CoversClass(ClientOptions::class)]
#[UsesClass(ValueObject::class)]
class ClientOptionsTest extends TestCase {

    /**
     * Everything defaults to "not set"
     *
     * @return void
     */
    public function testDefaultsAreNull(): void {
        $this->assertSame([], (new ClientOptions())->toArray());
    }

    /**
     * Options load from a config array, ignoring unknown keys
     *
     * @return void
     */
    public function testFromConfigArray(): void {
        $options = ClientOptions::fromArray([
            'signing_key' => 'signkey-prod-abc',
            'is_dev'      => false,
            'unknown'     => 'ignored',
        ]);

        $this->assertSame('signkey-prod-abc', $options->signing_key);
        $this->assertFalse($options->is_dev);
        $this->assertNull($options->event_key);
    }

    /**
     * Named arguments set individual options
     *
     * @return void
     */
    public function testNamedArguments(): void {
        $options = new ClientOptions(event_key: 'evt', serve_path: '/api');

        $this->assertSame(
            ['event_key' => 'evt', 'serve_path' => '/api'],
            $options->toArray()
        );
    }
}
