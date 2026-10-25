<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

/**
 * Built-in middleware behind `$ctx->logger`
 *
 * Port of the JS `LoggerMiddleware` (`builtInMiddleware` in
 * components/Inngest.ts). Every client runs it before its own middleware.
 * It gives the handler a ProxyLogger that stays quiet while steps replay,
 * and logs step and function errors.
 *
 * @package DealNews\Inngest\Middleware
 */
class LoggerMiddleware extends Middleware {

    /**
     * ID used in logs
     *
     * @var string
     */
    public const ID = 'inngest:logger';

    /**
     * The handler's logger for this request
     *
     * @var ProxyLogger|null
     */
    protected ?ProxyLogger $proxy = null;

    /**
     * {@inheritDoc}
     */
    public function getId(): string {
        return self::ID;
    }

    /**
     * Swaps the handler's logger for a ProxyLogger
     *
     * @param FunctionInput $input Input
     *
     * @return FunctionInput
     */
    public function transformFunctionInput(
        FunctionInput $input
    ): FunctionInput {
        $ctx = $input->ctx;

        $this->proxy = new ProxyLogger($this->client->getLogger(), [
            'runID'     => $ctx->run_id,
            'eventName' => $ctx->event->name,
        ]);

        $result      = clone $input;
        $result->ctx = $ctx->with(logger: $this->proxy);

        return $result;
    }

    /**
     * Turns the handler's logger on once replay is over
     *
     * @param RunArgs $args Arguments
     *
     * @return void
     */
    public function onMemoizationEnd(RunArgs $args): void {
        $this->proxy?->enable();
    }

    /**
     * Logs a step's error
     *
     * @param StepErrorArgs $args Arguments
     *
     * @return void
     */
    public function onStepError(StepErrorArgs $args): void {
        $this->proxy?->error(
            'Inngest step error',
            ['exception' => $args->error]
        );
    }

    /**
     * Logs an error thrown by the handler, then rethrows it
     *
     * @param WrapFunctionHandlerArgs $args Arguments
     *
     * @throws \Throwable Whatever the handler threw
     *
     * @return mixed
     */
    public function wrapFunctionHandler(
        WrapFunctionHandlerArgs $args
    ): mixed {
        try {
            $result = $args->next();
        } catch (\Throwable $e) {
            $this->proxy?->error(
                'Inngest function error',
                ['exception' => $e]
            );
            throw $e;
        }

        return $result;
    }
}
