<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * Tracks the branches of one `step->parallel()` or `step->race()` call
 *
 * Plays the part of JS `Promise.all()` / `Promise.race()`: each branch
 * runs in its own fiber, and the group decides once, when every branch has
 * returned (all), when any branch has returned (race), or when a branch
 * throws (both). Later results are ignored, like a settled promise.
 *
 * @package DealNews\Inngest\Execution
 */
class ParallelGroup {

    /**
     * Fiber waiting on the group; set once it suspends
     *
     * @var \Fiber<mixed, mixed, mixed, mixed>|null
     */
    public ?\Fiber $parent = null;

    /**
     * Whether the group has decided
     *
     * @var bool
     */
    public bool $settled = false;

    /**
     * Results so far, by branch key
     *
     * @var array<int|string, mixed>
     */
    protected array $results = [];

    /**
     * The value the group decided on
     *
     * @var mixed
     */
    protected mixed $value = null;

    /**
     * The error the group decided on
     *
     * @var \Throwable|null
     */
    protected ?\Throwable $error = null;

    /**
     * Creates the group
     *
     * @param list<int|string> $keys Branch keys, in argument order
     * @param bool             $race Settle on the first branch to finish
     */
    public function __construct(
        protected array $keys,
        protected bool $race
    ) {
        // Like `Promise.all([])`, no branches means done, with no results.
        if ($keys === [] && !$race) {
            $this->settled = true;
            $this->value   = [];
        }
    }

    /**
     * Records a branch that returned or threw
     *
     * @param int|string      $key   Branch key
     * @param mixed           $value What it returned
     * @param \Throwable|null $error What it threw
     *
     * @return bool True when this decided the group
     */
    public function settle(
        int|string $key,
        mixed $value,
        ?\Throwable $error
    ): bool {
        $decided = false;

        if (!$this->settled) {
            if ($error !== null) {
                $this->error = $error;
                $decided     = true;
            } elseif ($this->race) {
                $this->value = $value;
                $decided     = true;
            } else {
                $this->results[$key] = $value;
                if (count($this->results) === count($this->keys)) {
                    $this->value = $this->ordered();
                    $decided     = true;
                }
            }

            $this->settled = $decided;
        }

        return $decided;
    }

    /**
     * Returns the decided value, or throws the decided error
     *
     * @throws \Throwable The branch error the group settled on
     *
     * @return mixed
     */
    public function result(): mixed {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    /**
     * Returns the results in branch order
     *
     * @return array<int|string, mixed>
     */
    protected function ordered(): array {
        $ordered = [];
        foreach ($this->keys as $key) {
            $ordered[$key] = $this->results[$key];
        }

        return $ordered;
    }
}
