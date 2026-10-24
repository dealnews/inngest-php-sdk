<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

/**
 * Cancels a run when a matching event arrives
 *
 * Usage:
 *
 * ```php
 * new Cancellation(event: 'app/order.cancelled', match: 'data.order_id');
 * new Cancellation(
 *     event: 'app/user.deleted',
 *     if: 'async.data.id == event.data.user_id',
 *     timeout: '7d',
 * );
 * ```
 *
 * Edge cases:
 *
 * - `match` wins over `if` when both are set (same as JS).
 * - `timeout` accepts anything `Duration` does; integers are ms.
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Cancellation {

    /**
     * How long the cancellation applies (default: a year)
     *
     * @var int|float|string|\DateInterval|\DateTimeInterface|null
     */
    public int|float|string|\DateInterval|\DateTimeInterface|null $timeout;

    /**
     * Creates the cancellation
     *
     * @param string      $event   Event that cancels the run
     * @param string|null $if      Expression comparing `event` (the trigger)
     *                             and `async` (the cancel event)
     * @param string|null $match   Field that must be equal in both events,
     *                             e.g. `data.order_id`
     * @param int|float|string|\DateInterval|\DateTimeInterface|null $timeout
     *        How long the cancellation applies
     */
    public function __construct(
        public string $event,
        public ?string $if = null,
        public ?string $match = null,
        int|float|string|\DateInterval|\DateTimeInterface|null $timeout = null
    ) {
        $this->timeout = $timeout;
    }
}
