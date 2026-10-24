<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * Hashes step IDs and gives repeated IDs an index
 *
 * Port of `hashId()` and `resolveStepIdCollision()` (execution/engine.ts).
 * Inngest knows steps by the SHA-1 of their ID. Using the same ID twice in
 * a run is allowed: later uses become `id:1`, `id:2`, and so on.
 *
 * Usage:
 *
 * ```php
 * $ids = new StepIdResolver();
 * $ids->resolve('fetch', []);                          // ['fetch', null]
 * $ids->resolve('fetch', [sha1('fetch') => true]);     // ['fetch:1', 1]
 * ```
 *
 * Edge cases:
 *
 * - Keep one resolver per run: it remembers the next index for each ID, so
 *   indexes are handed out in order even if a later index is already taken.
 *
 * @package DealNews\Inngest\Execution
 */
class StepIdResolver {

    /**
     * Separator between a repeated ID and its index
     *
     * @var string
     */
    public const INDEX_SEPARATOR = ':';

    /**
     * Next index to try, by base ID
     *
     * @var array<string, int>
     */
    protected array $expected = [];

    /**
     * Hashes a step ID the way Inngest expects (SHA-1, hex)
     *
     * @param string $id Step ID
     *
     * @return string
     */
    public static function hash(string $id): string {
        return sha1($id);
    }

    /**
     * Returns the ID to use for a step, and its index when it repeats
     *
     * @param string              $base  ID the step was given
     * @param array<string, mixed> $taken Steps found so far, keyed by hashed
     *                                    ID
     *
     * @throws \LogicException When no free index is found (can't happen)
     *
     * @return array{string, int|null}
     */
    public function resolve(string $base, array $taken): array {
        $result = null;

        if (
            !isset($taken[static::hash($base)]) &&
            !isset($this->expected[$base])
        ) {
            $this->expected[$base] = 1;
            $result                = [$base, null];
        } else {
            $start = $this->expected[$base] ?? 1;
            $max   = $start + count($taken) + 1;
            for ($index = $start; $index < $max; $index++) {
                $id = $base . self::INDEX_SEPARATOR . $index;
                if (!isset($taken[static::hash($id)])) {
                    $this->expected[$base] = $index + 1;
                    $result                = [$id, $index];
                    break;
                }
            }
        }

        if ($result === null) {
            throw new \LogicException(
                "Could not resolve step ID collision for \"$base\"",
                7201
            );
        }

        return $result;
    }
}
