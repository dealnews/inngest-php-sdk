<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Config;

use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Env
 *
 * @package DealNews\Inngest\Tests\Config
 */
#[CoversClass(Env::class)]
class EnvTest extends TestCase {

    /**
     * Fixed variables are read, with empty values treated as unset
     *
     * @return void
     */
    public function testGetFromArray(): void {
        $env = new Env([
            Env::SIGNING_KEY => 'signkey-test-1',
            Env::EVENT_KEY   => '',
            'NUMBER'         => 5,
            'ARRAY'          => ['nope'],
        ]);

        $this->assertSame('signkey-test-1', $env->get(Env::SIGNING_KEY));
        $this->assertNull($env->get(Env::EVENT_KEY));
        $this->assertNull($env->get(Env::ENV));
        $this->assertSame('5', $env->get('NUMBER'));
        $this->assertNull($env->get('ARRAY'));
    }

    /**
     * Without an array, values come from getenv()
     *
     * @return void
     */
    public function testGetFromProcessEnv(): void {
        $name = 'INNGEST_PHP_SDK_TEST_' . bin2hex(random_bytes(4));
        putenv("$name=from-process");

        try {
            $env = new Env();
            $this->assertSame('from-process', $env->get($name));
            $this->assertNull($env->get($name . '_MISSING'));
        } finally {
            putenv($name);
        }
    }

    /**
     * getBool() parses the variable
     *
     * @return void
     */
    public function testGetBool(): void {
        $env = new Env([Env::DEV => '1', 'OTHER' => 'http://x']);

        $this->assertTrue($env->getBool(Env::DEV));
        $this->assertNull($env->getBool('OTHER'));
        $this->assertNull($env->getBool('MISSING'));
    }

    /**
     * parseBool() matches the JS parseAsBoolean
     *
     * @param mixed     $input    Value
     * @param bool|null $expected Result
     *
     * @return void
     */
    #[DataProvider('boolProvider')]
    public function testParseBool(mixed $input, ?bool $expected): void {
        $this->assertSame($expected, Env::parseBool($input));
    }

    /**
     * Cases for testParseBool
     *
     * @return array<string, array{mixed, ?bool}>
     */
    public static function boolProvider(): array {
        return [
            'bool true'     => [true, true],
            'bool false'    => [false, false],
            'int 1'         => [1, true],
            'int 0'         => [0, false],
            'float'         => [0.5, true],
            'string true'   => ['true', true],
            'upper TRUE'    => [' TRUE ', true],
            'string 1'      => ['1', true],
            'string false'  => ['false', false],
            'string 0'      => ['0', false],
            'undefined'     => ['undefined', null],
            'yes'           => ['yes', null],
            'url'           => ['http://localhost:8288', null],
            'null'          => [null, null],
            'array'         => [[], null],
        ];
    }
}
