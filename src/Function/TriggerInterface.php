<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Trigger configuration for a function
 */
interface TriggerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
