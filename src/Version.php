<?php

declare(strict_types=1);

namespace DealNews\Inngest;

use Composer\InstalledVersions;

/**
 * SDK name and version, as reported to Inngest
 *
 * Inngest asks third-party SDKs to identify themselves by their GitHub repo
 * name, which matches our Composer package name. The version comes from
 * Composer's runtime API; when that's unavailable we report `dev`.
 *
 * Usage:
 *
 * ```php
 * $version = new Version();
 * $version->identifier(); // "dealnews/inngest-php-sdk:v1.2.3"
 * ```
 *
 * Edge cases:
 *
 * - Developing this library directly (it's the root package) yields branch
 *   versions such as `dev-version-1.x`; those are reported as-is.
 * - A leading `v` on tag versions is stripped, so `v1.2.3` and `1.2.3`
 *   both report as `1.2.3`.
 *
 * @package DealNews\Inngest
 */
class Version {

    /**
     * Package name, used as the SDK name in headers and payloads
     *
     * @var string
     */
    public const NAME = 'dealnews/inngest-php-sdk';

    /**
     * Version reported when Composer can't tell us
     *
     * @var string
     */
    public const FALLBACK = 'dev';

    /**
     * Returns the SDK version, without a leading `v`
     *
     * @return string
     */
    public function version(): string {
        $version = $this->composerVersion();

        if ($version === null || $version === '') {
            $version = self::FALLBACK;
        } elseif (preg_match('/^v\\d/', $version)) {
            $version = substr($version, 1);
        }

        return $version;
    }

    /**
     * Returns the SDK identifier sent in `User-Agent` and `X-Inngest-SDK`
     *
     * Numeric versions get a `v` prefix to match the JS SDK's
     * `inngest-js:v4.26.0` format; non-numeric versions (`dev`, branch
     * names) are appended as-is.
     *
     * @return string
     */
    public function identifier(): string {
        $version = $this->version();
        $prefix  = preg_match('/^\\d/', $version) ? 'v' : '';

        return self::NAME . ':' . $prefix . $version;
    }

    /**
     * Looks up the installed version of a package from Composer
     *
     * @param string $package Package name; defaults to this SDK
     *
     * @return string|null Null when Composer's runtime API is unavailable or
     *                     doesn't know about the package
     */
    protected function composerVersion(string $package = self::NAME): ?string {
        $version = null;

        if (class_exists(InstalledVersions::class)) {
            try {
                $version = InstalledVersions::getPrettyVersion($package);
            } catch (\OutOfBoundsException) {
                $version = null;
            }
        }

        return $version;
    }
}
