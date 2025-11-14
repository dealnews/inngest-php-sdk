<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents concurrency configuration for an Inngest function
 *
 * Concurrency limits the total number of concurrent steps that can occur
 * across all runs of a function. A maximum of two concurrency options
 * can be specified.
 *
 * ## Usage
 *
 * ```php
 * // Simple limit (10 concurrent steps max)
 * $concurrency = new Concurrency(limit: 10);
 *
 * // With key for grouping
 * $concurrency = new Concurrency(
 *     limit: 5,
 *     key: 'event.data.user_id'
 * );
 *
 * // With scope across environments
 * $concurrency = new Concurrency(
 *     limit: 100,
 *     scope: 'account'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - A limit of 0 means use maximum available concurrency (unlimited)
 * - The `key` is an expression evaluated against event data
 * - Scope defaults to "fn" (per-function) if not specified
 */
class Concurrency
{
    /**
     * @param int $limit Maximum number of concurrent steps (0 = unlimited)
     * @param string|null $key Optional expression for grouping concurrency (e.g., "event.data.user_id")
     * @param string|null $scope Scope of concurrency limit: "fn" (default), "env", or "account"
     */
    public function __construct(
        protected int $limit,
        protected ?string $key = null,
        protected ?string $scope = null
    ) {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Concurrency limit must be >= 0');
        }

        if ($scope !== null && !in_array($scope, ['fn', 'env', 'account'], true)) {
            throw new \InvalidArgumentException('Concurrency scope must be one of: fn, env, account');
        }
    }

    /**
     * Get the concurrency limit
     *
     * @return int Maximum concurrent steps
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the concurrency key expression
     *
     * @return string|null Expression for grouping concurrency
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Get the concurrency scope
     *
     * @return string|null One of: "fn", "env", "account"
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Convert to array for sync payload
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'limit' => $this->limit,
        ];

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        if ($this->scope !== null) {
            $data['scope'] = $this->scope;
        }

        return $data;
    }
}
