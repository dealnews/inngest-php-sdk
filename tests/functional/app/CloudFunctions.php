<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional\app;

use DealNews\Inngest\Client;
use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Tests\functional\StateStore;

/**
 * Functions served by the cloud-mode test app
 *
 * Every request between this app and the self-hosted server is signed, so
 * a run that completes proves signing works both ways over real HTTP
 * (raw-body HMACs, D7).
 *
 * @package DealNews\Inngest\Tests\functional\app
 */
class CloudFunctions {

    /**
     * Creates every function on the client
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     *
     * @return void
     */
    public static function register(Client $client, StateStore $store): void {
        $client->createFunction(
            new FunctionOptions(
                'cloud-hello',
                triggers: [new EventTrigger('cloud/hello')]
            ),
            static fn (Context $ctx) => [
                'hello' => $ctx->event->data['name'] ?? null,
            ]
        );

        // Steps, a sleep, and unicode/slashes in data, so several signed
        // requests and responses carry bodies that JSON escaping could
        // change
        foreach (['on' => true, 'off' => false] as $suffix => $checkpointing) {
            $client->createFunction(
                new FunctionOptions(
                    "cloud-steps-$suffix",
                    triggers: [new EventTrigger("cloud/steps.$suffix")],
                    retries: 0,
                    checkpointing: $checkpointing
                ),
                static function (Context $ctx) use ($store): array {
                    $store->append($ctx->run_id, 'request');
                    $a = $ctx->step->run(
                        'a',
                        static fn () => 'héllo / wörld ✓'
                    );
                    $ctx->step->sleep('nap', '1s');
                    $b = $ctx->step->run(
                        'b',
                        static fn () => $a . ' <' . ($ctx->event->data['x'] ??
                            '') . '>'
                    );

                    return ['a' => $a, 'b' => $b];
                }
            );
        }
    }
}
