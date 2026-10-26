<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

/**
 * One span in a run's trace tree
 *
 * The run is the root; steps are its children; step attempts are theirs.
 * The dev server leaves `stepType` empty and only sometimes sets `stepOp`,
 * so match spans by name and status (the JS helpers also match
 * `stepType`, which this server version doesn't fill in).
 *
 * @package DealNews\Inngest\Tests\functional
 */
class Span {

    /**
     * Creates the span
     *
     * @param string      $name      Name (step name, or a hashed ID)
     * @param string      $status    Status, e.g. `COMPLETED`, `FAILED`
     * @param int|null    $attempts  Attempt count
     * @param string|null $output_id ID for `DevServer::output()`
     * @param string|null $step_op   Step op, when the server knows it
     * @param list<Span>  $children  Child spans
     */
    public function __construct(
        public string $name,
        public string $status,
        public ?int $attempts = null,
        public ?string $output_id = null,
        public ?string $step_op = null,
        public array $children = []
    ) {
    }

    /**
     * Builds a span tree from GraphQL
     *
     * @param array<string, mixed> $raw GraphQL span
     *
     * @return self
     */
    public static function fromArray(array $raw): self {
        return new self(
            (string)($raw['name'] ?? ''),
            (string)($raw['status'] ?? ''),
            $raw['attempts'] ?? null,
            $raw['outputID'] ?? null,
            ($raw['stepOp'] ?? '') === '' ? null : $raw['stepOp'],
            array_map(
                static fn (array $child) => self::fromArray($child),
                $raw['childrenSpans'] ?? []
            )
        );
    }

    /**
     * Finds the first span (depth first, this one included) with a name
     *
     * @param string      $name   Name
     * @param string|null $status Status to match too
     *
     * @return self|null
     */
    public function find(string $name, ?string $status = null): ?self {
        $found = null;

        if (
            $this->name === $name &&
            ($status === null || $this->status === $status)
        ) {
            $found = $this;
        } else {
            foreach ($this->children as $child) {
                $found = $child->find($name, $status);
                if ($found !== null) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Returns the names of the direct children
     *
     * @return list<string>
     */
    public function childNames(): array {
        return array_map(
            static fn (self $child) => $child->name,
            $this->children
        );
    }
}
