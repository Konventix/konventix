<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;
use Throwable;

/**
 * One or more consumers of a recorded refund's domain events threw
 * (#236 fix round 1, review finding 3).
 *
 * Aggregate on purpose. `RelayRefundEvents` dispatches every event before it
 * throws, so a cart order whose first line has a broken consumer still tells
 * the module about lines two and three — the failure mode the plain `foreach`
 * had was that everything after the first throw was skipped silently, with
 * the refund already committed and nothing to replay it.
 *
 * Each individual throwable is `report()`ed as it happens, so it reaches the
 * exception handler (and Pulse) even where this aggregate is later swallowed.
 * This one exists so the *job* fails: that is what puts the whole fan-out in
 * front of Horizon's retries and, ultimately, in `failed_jobs`.
 */
final class RefundEventRelayFailedException extends RuntimeException
{
    /**
     * @param  list<Throwable>  $failures
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $failures,
    ) {
        parent::__construct(sprintf(
            'Relaying the refund events for order [%s] failed for %d of them; the refund itself is recorded. First failure: %s',
            $orderId,
            count($failures),
            $failures[0]->getMessage(),
        ), previous: $failures[0]);
    }
}
