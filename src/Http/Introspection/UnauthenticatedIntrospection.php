<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http\Introspection;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Introspection body (`GET`) for callers that didn't prove who they are
 *
 * Port of JS `UnauthenticatedIntrospection` (types.ts). Only dev mode
 * serves this to unsigned requests; cloud mode answers them with `401`.
 *
 * @package DealNews\Inngest\Http\Introspection
 */
class UnauthenticatedIntrospection extends ValueObject {

    /**
     * Introspection schema version
     *
     * @var string
     */
    public const SCHEMA_VERSION = '2024-05-24';

    /**
     * Creates the body
     *
     * @param IntrospectionExtra $extra           Extra details
     * @param bool               $has_event_key   Whether an event key is set
     * @param bool               $has_signing_key Whether a signing key is
     *                                            set
     * @param int                $function_count  Number of function configs
     *                                            (failure handlers count)
     * @param string             $mode            `dev` or `cloud`
     * @param string             $schema_version  Schema version
     */
    public function __construct(
        public IntrospectionExtra $extra = new IntrospectionExtra(),
        public bool $has_event_key = false,
        public bool $has_signing_key = false,
        public int $function_count = 0,
        public string $mode = 'dev',
        public string $schema_version = self::SCHEMA_VERSION
    ) {
    }
}
