<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http\Introspection;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * The `extra` block of an introspection response
 *
 * @package DealNews\Inngest\Http\Introspection
 */
class IntrospectionExtra extends ValueObject {

    /**
     * Creates the block
     *
     * @param bool      $native_crypto Whether signing uses native crypto;
     *                                 always true (ext-hash)
     * @param bool|null $is_streaming  Whether responses stream; false, and
     *                                 only sent when authenticated
     */
    public function __construct(
        public bool $native_crypto = true,
        public ?bool $is_streaming = null
    ) {
    }
}
