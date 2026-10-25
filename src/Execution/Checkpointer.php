<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\StaleDispatchError;
use DealNews\Inngest\Support\Retry;

/**
 * Sends checkpoints for one request
 *
 * Port of JS `InngestExecutionEngine.checkpoint()` in async checkpointing
 * mode: posts the steps that ran, with the request's identifiers, retrying
 * with backoff (5 attempts, 100ms base) unless Inngest says the request is
 * stale.
 *
 * @package DealNews\Inngest\Execution
 */
class Checkpointer {

    /**
     * Attempts per checkpoint
     *
     * @var int
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Base retry delay in milliseconds
     *
     * @var int
     */
    public const BASE_DELAY_MS = 100;

    /**
     * Creates the checkpointer
     *
     * @param CheckpointConfig $config             Settings
     * @param ApiClient        $api                Inngest API client
     * @param Retry            $retry              Retry helper
     * @param string           $run_id             Run ID
     * @param string           $fn_id              Inngest's function ID
     * @param string|null      $qi_id              Queue item ID
     * @param string|null      $request_id         `X-Request-Id`
     * @param int|null         $generation_id      `X-Inngest-Generation-Id`
     * @param int              $request_started_at When the request started,
     *                                             in milliseconds
     */
    public function __construct(
        public readonly CheckpointConfig $config,
        protected ApiClient $api,
        protected Retry $retry,
        protected string $run_id,
        protected string $fn_id,
        protected ?string $qi_id,
        protected ?string $request_id,
        protected ?int $generation_id,
        protected int $request_started_at
    ) {
    }

    /**
     * Sends steps to Inngest
     *
     * @param list<OutgoingOp> $ops Steps that ran
     *
     * @throws StaleDispatchError When Inngest says this request is stale
     * @throws ExecutionException When the queue item ID is missing (7104)
     * @throws \Throwable         When every attempt fails
     *
     * @return void
     */
    public function checkpoint(array $ops): void {
        if ($this->qi_id === null || $this->qi_id === '') {
            throw new ExecutionException(
                'Missing queueItemId for async checkpointing',
                7104
            );
        }

        $this->retry->run(
            fn () => $this->api->checkpointAsync(
                $this->run_id,
                $this->payload($ops)
            ),
            self::MAX_ATTEMPTS,
            self::BASE_DELAY_MS,
            static fn (\Throwable $e) => !$e instanceof StaleDispatchError
        );
    }

    /**
     * Builds the checkpoint body; unset IDs are left out
     *
     * @param list<OutgoingOp> $ops Steps that ran
     *
     * @return array<string, mixed>
     */
    protected function payload(array $ops): array {
        $payload = [
            'run_id' => $this->run_id,
            'fn_id'  => $this->fn_id,
            'qi_id'  => $this->qi_id,
        ];
        if ($this->request_id !== null) {
            $payload['request_id'] = $this->request_id;
        }
        if ($this->generation_id !== null) {
            $payload['generation_id'] = $this->generation_id;
        }
        $payload['request_started_at'] = $this->request_started_at;
        $payload['steps']              = $ops;
        $payload['ts']                 = $this->nowMs();

        return $payload;
    }

    /**
     * Returns the time in milliseconds
     *
     * @return int
     */
    protected function nowMs(): int {
        return (int)floor(microtime(true) * 1000);
    }
}
