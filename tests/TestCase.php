<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests;

/**
 * Base test case for the Inngest PHP SDK test suite
 *
 * Every test class extends this so shared helpers (fixture loading, fake
 * requests, mocked HTTP clients) live in one place.
 *
 * @package DealNews\Inngest\Tests
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

    /**
     * Directory holding JSON wire fixtures
     *
     * @var string
     */
    public const FIXTURE_DIR = __DIR__ . '/fixtures';

    /**
     * Loads a fixture file's raw contents
     *
     * Raw strings matter here: signatures are computed over exact bytes, so
     * fixtures are never decoded and re-encoded before use.
     *
     * @param string $name Path relative to tests/fixtures
     *
     * @throws \RuntimeException When the fixture does not exist
     *
     * @return string
     */
    protected function loadFixture(string $name): string {
        $path = self::FIXTURE_DIR . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException("Fixture not found: $name", 1);
        }

        return (string)file_get_contents($path);
    }

    /**
     * Asserts two JSON documents hold the same data
     *
     * Object key order is ignored (JS key order often follows user input);
     * list order, types, and `{}` vs `[]` are not.
     *
     * @param string $expected Expected JSON
     * @param string $actual   Actual JSON
     * @param string $message  Failure message
     *
     * @return void
     */
    protected function assertJsonDataSame(
        string $expected,
        string $actual,
        string $message = ''
    ): void {
        $this->assertSame(
            static::canonicalJson($expected),
            static::canonicalJson($actual),
            $message
        );
    }

    /**
     * Re-encodes JSON with object keys sorted, keeping `{}` vs `[]`
     *
     * @param string $json JSON
     *
     * @return string
     */
    protected static function canonicalJson(string $json): string {
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        return (string)json_encode(
            static::sortObjects($data),
            JSON_PRETTY_PRINT      |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Recursively sorts object keys
     *
     * @param mixed $value Decoded JSON (objects as stdClass)
     *
     * @return mixed
     */
    protected static function sortObjects(mixed $value): mixed {
        $result = $value;

        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            ksort($props, SORT_STRING);
            $result = new \stdClass();
            foreach ($props as $key => $item) {
                $result->$key = static::sortObjects($item);
            }
        } elseif (is_array($value)) {
            $result = array_map(
                static fn ($item) => static::sortObjects($item),
                $value
            );
        }

        return $result;
    }

    /**
     * Loads a JSON fixture and decodes it to an associative array
     *
     * @param string $name Path relative to tests/fixtures
     *
     * @return array<mixed>
     */
    protected function loadJsonFixture(string $name): array {
        return json_decode(
            $this->loadFixture($name),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
