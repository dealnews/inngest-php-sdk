<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional\app;

use DealNews\Inngest\Client;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\Middleware;
use DealNews\Inngest\Middleware\StepArgs;
use DealNews\Inngest\Middleware\WrapFunctionHandlerArgs;
use DealNews\Inngest\Tests\functional\StateStore;

/**
 * Middleware for the JS `middleware/lifecycle.test.ts` scenarios
 *
 * Records, per run, one entry per request: the instance's step counter
 * when each step starts (must start at 1 in every request), and the value
 * `wrapFunctionHandler` saw from `transformFunctionInput` (must be the
 * same instance within a request).
 *
 * @package DealNews\Inngest\Tests\functional\app
 */
class LifecycleMiddleware extends Middleware {

    /**
     * Steps started by this instance
     *
     * @var int
     */
    protected int $counter = 0;

    /**
     * Set by transformFunctionInput, read by wrapFunctionHandler
     *
     * @var string
     */
    protected string $data = '';

    /**
     * Creates the middleware
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     */
    public function __construct(Client $client, protected StateStore $store) {
        parent::__construct($client);
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string {
        return 'test';
    }

    /**
     * Remembers a value for wrapFunctionHandler
     *
     * @param FunctionInput $input Input
     *
     * @return FunctionInput
     */
    public function transformFunctionInput(
        FunctionInput $input
    ): FunctionInput {
        $this->data = 'set-in-transform';

        return $input;
    }

    /**
     * Records what transformFunctionInput left behind
     *
     * @param WrapFunctionHandlerArgs $args Args
     *
     * @return mixed
     */
    public function wrapFunctionHandler(
        WrapFunctionHandlerArgs $args
    ): mixed {
        $this->store->append($args->ctx->run_id, [
            'hook' => 'wrapFunctionHandler',
            'data' => $this->data,
        ]);

        return $args->next();
    }

    /**
     * Counts started steps
     *
     * @param StepArgs $args Args
     *
     * @return void
     */
    public function onStepStart(StepArgs $args): void {
        $this->counter++;
        $this->store->append($args->ctx->run_id, [
            'hook'    => 'onStepStart',
            'counter' => $this->counter,
            'step'    => $args->step_info->options->id,
        ]);
    }
}
