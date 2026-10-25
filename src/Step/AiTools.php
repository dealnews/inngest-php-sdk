<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\StepOp;

/**
 * AI step tools, handed to a function as `$ctx->step->ai`
 *
 * Port of `step.ai` (components/InngestStepTools.ts), without the
 * provider model helpers from `@inngest/ai`: `infer()` takes the URL,
 * format, and body directly.
 *
 * Usage:
 *
 * ```php
 * $reply = $ctx->step->ai->infer(
 *     'summarize',
 *     url: 'https://api.openai.com/v1/chat/completions',
 *     format: AiFormat::OpenAiChat,
 *     body: ['model' => 'gpt-4o', 'messages' => $messages],
 *     auth_key: getenv('OPENAI_API_KEY')
 * );
 * ```
 *
 * @package DealNews\Inngest\Step
 */
class AiTools {

    /**
     * Creates the tools
     *
     * @param Engine $engine Engine running the function
     */
    public function __construct(protected Engine $engine) {
    }

    /**
     * Has Inngest call an AI model, as a step
     *
     * Inngest makes the request, so the function isn't running while the
     * model works. Inngest can show and edit the body in its UI.
     *
     * @param string|StepOptions         $id       Step ID or options
     * @param string                     $url      Model endpoint URL
     * @param AiFormat|string            $format   Request/response format
     * @param array<mixed>               $body     Request body
     * @param string|null                $auth_key API key for the model
     * @param array<string, string>|null $headers  Extra request headers
     *
     * @throws \DealNews\Inngest\Error\StepError When the call failed for
     *                                           good
     *
     * @return mixed The model's response
     */
    public function infer(
        string|StepOptions $id,
        string $url,
        AiFormat|string $format,
        array $body,
        ?string $auth_key = null,
        ?array $headers = null
    ): mixed {
        $options = StepOptions::from($id);

        $opts = ['type' => 'step.ai.infer', 'url' => $url];
        if ($headers !== null) {
            $opts['headers'] = $headers === [] ? new \stdClass() : $headers;
        }
        if ($auth_key !== null) {
            $opts['auth_key'] = $auth_key;
        }
        $opts['format'] = $format instanceof AiFormat ?
            $format->value :
            $format;
        $opts['body'] = $body === [] ? new \stdClass() : $body;

        return $this->engine->step(new StepOp(
            id: $options->id,
            op: OpCode::AiGateway,
            display_name: $options->name ?? $options->id,
            opts: $opts
        ));
    }

    /**
     * Runs AI code (an SDK call, an agent, ...) as a retriable step
     *
     * Same as `step->run()`, but Inngest shows it as an AI step and lets
     * the input be edited in its UI.
     *
     * @param string|StepOptions $id       Step ID or options
     * @param callable           $callable Code to run
     * @param mixed              ...$input Arguments for the callable
     *
     * @throws \DealNews\Inngest\Error\StepError When the step failed for
     *                                           good
     *
     * @return mixed The callable's return value (null for void)
     */
    public function wrap(
        string|StepOptions $id,
        callable $callable,
        mixed ...$input
    ): mixed {
        $input = array_values($input);

        return $this->engine->step(
            StepTools::runOp(StepOptions::from($id), $input, 'step.ai.wrap'),
            \Closure::fromCallable($callable),
            $input
        );
    }
}
