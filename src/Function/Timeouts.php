<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents timeout configuration for an Inngest function
 *
 * Allows specifying timeouts for function start and finish operations.
 * These are duration strings in the format used by Go (e.g., "30s", "5m").
 */
class Timeouts
{
    /**
     * @param string|null $start Optional timeout for function start operation
     * @param string|null $finish Optional timeout for function finish operation
     */
    public function __construct(
        protected ?string $start = null,
        protected ?string $finish = null
    ) {
    }

    /**
     * Get the start timeout
     *
     * @return string|null The start timeout value or null
     */
    public function getStart(): ?string
    {
        return $this->start;
    }

    /**
     * Get the finish timeout
     *
     * @return string|null The finish timeout value or null
     */
    public function getFinish(): ?string
    {
        return $this->finish;
    }

    /**
     * Convert to array for sync payload
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [];
        if ($this->start !== null) {
            $data['start'] = $this->start;
        }
        if ($this->finish !== null) {
            $data['finish'] = $this->finish;
        }
        return $data;
    }
}
