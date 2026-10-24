<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests;

use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for SDK name and version reporting
 *
 * @package DealNews\Inngest\Tests
 */
#[CoversClass(Version::class)]
class VersionTest extends TestCase {

    /**
     * The SDK name must match composer.json (Inngest's naming rule)
     *
     * @return void
     */
    public function testNameMatchesComposerJson(): void {
        $composer = json_decode(
            (string)file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($composer['name'], Version::NAME);
    }

    /**
     * Running from this repo, Composer knows the root package's version
     *
     * @return void
     */
    public function testVersionFromComposer(): void {
        $version = (new Version())->version();

        $this->assertNotSame('', $version);
        $this->assertStringStartsNotWith('v', $version);
    }

    /**
     * A package Composer doesn't know about falls back to "dev"
     *
     * @return void
     */
    public function testUnknownPackageFallsBack(): void {
        $subject = new class() extends Version {
            /**
             * @param string $package Ignored; always looks up a fake package
             *
             * @return string|null
             */
            protected function composerVersion(
                string $package = self::NAME
            ): ?string {
                return parent::composerVersion('dealnews/not-installed');
            }
        };

        $this->assertSame('dev', $subject->version());
    }

    /**
     * Version normalization and identifier formatting
     *
     * @param string|null $raw        Version Composer reports
     * @param string      $version    Expected version()
     * @param string      $identifier Expected identifier()
     *
     * @return void
     */
    #[DataProvider('versionProvider')]
    public function testVersionAndIdentifier(
        ?string $raw,
        string $version,
        string $identifier
    ): void {
        $subject = $this->versionReporting($raw);

        $this->assertSame($version, $subject->version());
        $this->assertSame($identifier, $subject->identifier());
    }

    /**
     * Cases for testVersionAndIdentifier
     *
     * @return array<string, array{?string, string, string}>
     */
    public static function versionProvider(): array {
        return [
            'tag with v' => [
                'v1.2.3',
                '1.2.3',
                'dealnews/inngest-php-sdk:v1.2.3',
            ],
            'tag without v' => [
                '1.2.3',
                '1.2.3',
                'dealnews/inngest-php-sdk:v1.2.3',
            ],
            'pre-release' => [
                'v2.0.0-beta.1',
                '2.0.0-beta.1',
                'dealnews/inngest-php-sdk:v2.0.0-beta.1',
            ],
            'branch' => [
                'dev-version-1.x',
                'dev-version-1.x',
                'dealnews/inngest-php-sdk:dev-version-1.x',
            ],
            'branch alias' => [
                '1.x-dev',
                '1.x-dev',
                'dealnews/inngest-php-sdk:v1.x-dev',
            ],
            'word starting with v' => [
                'vendor-fork',
                'vendor-fork',
                'dealnews/inngest-php-sdk:vendor-fork',
            ],
            'unknown' => [
                null,
                'dev',
                'dealnews/inngest-php-sdk:dev',
            ],
            'empty' => [
                '',
                'dev',
                'dealnews/inngest-php-sdk:dev',
            ],
        ];
    }

    /**
     * Builds a Version whose Composer lookup returns a fixed value
     *
     * @param string|null $raw Version to report
     *
     * @return Version
     */
    protected function versionReporting(?string $raw): Version {
        return new class($raw) extends Version {
            /**
             * @param string|null $raw Version to report
             */
            public function __construct(protected ?string $raw) {
            }

            /**
             * @param string $package Ignored
             *
             * @return string|null
             */
            protected function composerVersion(
                string $package = self::NAME
            ): ?string {
                return $this->raw;
            }
        };
    }
}
