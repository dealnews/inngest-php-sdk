<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Client;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Middleware\Middleware;
use DealNews\Inngest\Middleware\RunArgs;

/**
 * Middleware that records `onRegister()` calls and can throw from hooks
 *
 * @package DealNews\Inngest\Tests\mock
 */
class RegisterMiddleware extends Middleware {

    /**
     * Recorded `onRegister()` calls: client and function
     *
     * A static, because `onRegister()` is static. Reset it in tests.
     *
     * @var list<array{Client, ?InngestFunction}>
     */
    public static array $registered = [];

    /**
     * Records the call
     *
     * @param Client               $client   Client
     * @param InngestFunction|null $function Function
     *
     * @return void
     */
    public static function onRegister(
        Client $client,
        ?InngestFunction $function
    ): void {
        self::$registered[] = [$client, $function];
    }

    /**
     * Always throws, to test that `on*` errors are only logged
     *
     * @param RunArgs $args Arguments
     *
     * @throws \RuntimeException Always
     *
     * @return void
     */
    public function onRunStart(RunArgs $args): void {
        throw new \RuntimeException('hook broke');
    }
}
