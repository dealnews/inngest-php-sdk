<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Execution\CheckpointConfig;
use DealNews\Inngest\Execution\Checkpointer;
use DealNews\Inngest\Execution\OutgoingOp;

/**
 * Checkpointer that records checkpoints instead of calling Inngest
 *
 * Usage:
 *
 * ```php
 * $checkpointer = new RecordingCheckpointer(new CheckpointConfig());
 * // ... run an Engine with it ...
 * $checkpointer->sent; // list of op lists
 * ```
 *
 * @package DealNews\Inngest\Tests\mock
 */
class RecordingCheckpointer extends Checkpointer {

    /**
     * Checkpoints sent, oldest first
     *
     * @var list<list<OutgoingOp>>
     */
    public array $sent = [];

    /**
     * Thrown by every checkpoint, when set
     *
     * @var \Throwable|null
     */
    public ?\Throwable $error = null;

    /**
     * Creates the checkpointer
     *
     * @param CheckpointConfig $config Settings
     */
    public function __construct(CheckpointConfig $config) {
        $this->config = $config;
    }

    /**
     * Records the ops, or throws `$error`
     *
     * @param list<OutgoingOp> $ops Ops
     *
     * @throws \Throwable `$error`
     *
     * @return void
     */
    public function checkpoint(array $ops): void {
        if ($this->error !== null) {
            throw $this->error;
        }

        $this->sent[] = $ops;
    }
}
