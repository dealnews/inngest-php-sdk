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
