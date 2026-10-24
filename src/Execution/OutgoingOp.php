<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Error\SerializedError;

/**
 * A step operation sent to Inngest
 *
 * Port of JS `OutgoingOp` (types.ts). `data` is tracked separately from
 * "not set", because some responses send `data: null` and others leave it
 * out.
 *
 * @package DealNews\Inngest\Execution
 */
class OutgoingOp implements \JsonSerializable {

    /**
     * Step result
     *
     * @var mixed
     */
    public mixed $data = null;

    /**
     * Whether `data` is set (and so sent, even when null)
     *
     * @var bool
     */
    public bool $has_data = false;

    /**
     * Serialized error
     *
     * @var SerializedError|null
     */
    public ?SerializedError $error = null;

    /**
     * Error thrown by the step, before serialization
     *
     * @var \Throwable|null
     */
    public ?\Throwable $thrown = null;

    /**
     * When the step ran: `a` = start, `b` = duration, both in nanoseconds
     *
     * @var array{a: int, b: int}|null
     */
    public ?array $timing = null;

    /**
     * Creates the op
     *
     * @param string               $id             Hashed step ID
     * @param OpCode               $op             Op code
     * @param string|null          $name           Name (tool-specific)
     * @param array<string, mixed>|null $opts      Options; `[]` is sent as
     *                                             `{}`
     * @param string|null          $display_name   Name shown in the UI
     * @param string|null          $userland_id    ID the user gave (with
     *                                             index suffix)
     * @param int|null             $userland_index Index for repeated IDs
     */
    public function __construct(
        public string $id,
        public OpCode $op,
        public ?string $name = null,
        public ?array $opts = null,
        public ?string $display_name = null,
        public ?string $userland_id = null,
        public ?int $userland_index = null
    ) {
    }

    /**
     * Sets the data
     *
     * @param mixed $data Data
     *
     * @return static
     */
    public function withData(mixed $data): static {
        $this->data     = $data;
        $this->has_data = true;

        return $this;
    }

    /**
     * Returns the op in wire format
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        $result = ['id' => $this->id, 'op' => $this->op->value];

        if ($this->name !== null) {
            $result['name'] = $this->name;
        }
        if ($this->opts !== null) {
            $result['opts'] = $this->opts === [] ?
                new \stdClass() :
                $this->opts;
        }
        if ($this->display_name !== null) {
            $result['displayName'] = $this->display_name;
        }
        if ($this->userland_id !== null) {
            $result['userland'] = ['id' => $this->userland_id];
            if ($this->userland_index !== null) {
                $result['userland']['index'] = $this->userland_index;
            }
        }
        if ($this->has_data) {
            $result['data'] = $this->data;
        }
        if ($this->error !== null) {
            $result['error'] = $this->error;
        }
        if ($this->timing !== null) {
            $result['timing'] = $this->timing;
        }

        return $result;
    }
}
