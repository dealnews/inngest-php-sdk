<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Cron-based trigger for a function
 */
class TriggerCron implements TriggerInterface
{
    /**
     * @param string $cron Unix cron expression (e.g., "0 0 * * *")
     */
    public function __construct(protected string $cron)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['cron' => $this->cron];
    }

    public function getCron(): string
    {
        return $this->cron;
    }
}
