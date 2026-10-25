<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Client;

/**
 * Adds values to every handler's `$ctx->extras`
 *
 * Port of JS `dependencyInjectionMiddleware` (middleware/
 * dependencyInjection.ts).
 *
 * Usage:
 *
 * ```php
 * $inngest = new Client('my-app', new ClientOptions(
 *     middleware: [
 *         fn (Client $client) => new DependencyInjection(
 *             $client,
 *             ['db' => $db]
 *         ),
 *     ],
 * ));
 *
 * // In a handler:
 * $db = $ctx->extras['db'];
 * ```
 *
 * @package DealNews\Inngest\Middleware
 */
class DependencyInjection extends Middleware {

    /**
     * ID used in logs
     *
     * @var string
     */
    public const ID = 'inngest:dependency-injection';

    /**
     * Creates the middleware
     *
     * @param Client               $client Client
     * @param array<string, mixed> $values Values to add
     */
    public function __construct(Client $client, protected array $values) {
        parent::__construct($client);
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string {
        return self::ID;
    }

    /**
     * Adds the values to the context
     *
     * @param FunctionInput $input Input
     *
     * @return FunctionInput
     */
    public function transformFunctionInput(
        FunctionInput $input
    ): FunctionInput {
        $result      = clone $input;
        $result->ctx = $input->ctx->with(extras: $this->values);

        return $result;
    }
}
