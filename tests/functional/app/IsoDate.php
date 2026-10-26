<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional\app;

/**
 * A date that JSON-encodes like a JS `Date` (ISO string)
 *
 * Stands in for JS `new Date(...)` in the step-output serialization
 * scenario: the handler must see the JSON form, not this object, whether
 * the step was checkpointed or replayed.
 *
 * @package DealNews\Inngest\Tests\functional\app
 */
class IsoDate implements \JsonSerializable {

    /**
     * Creates the date
     *
     * @param string $date Any date PHP can parse
     */
    public function __construct(protected string $date) {
    }

    /**
     * Returns the ISO string, like JS `Date.prototype.toJSON()`
     *
     * @return string
     */
    public function jsonSerialize(): string {
        return (new \DateTimeImmutable(
            $this->date,
            new \DateTimeZone('UTC')
        ))->format('Y-m-d\\TH:i:s.v\\Z');
    }
}
