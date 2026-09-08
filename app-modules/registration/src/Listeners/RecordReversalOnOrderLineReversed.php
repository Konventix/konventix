<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\OrderLineReversed;
use Konventix\Registration\Actions\RecordPaymentReversedForRegistration;

/**
 * The mirror of `RecordPaymentOnOrderLineSettled` (ADR-0057 point 5): the
 * per-line counterpart of `RecordReversalOnPaymentReversed` for a cart
 * order, whose lines an order-level event alone cannot tell apart.
 * `OrderLineReversed` is emitted only for a `settles_type: 'cart'` order
 * (its own docblock says why), so the two reversal listeners partition on
 * which event Commerce chose to emit, never on a flag either one checks.
 *
 * `Konventix\Commerce\Events\OrderLineReversed` is the one Commerce class
 * this listener imports, allowed because events are the public contract
 * between modules (ADR-0004). A `product`-kind line is Commerce's own
 * business and is ignored here.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderLineSettled`.
 */
final class RecordReversalOnOrderLineReversed
{
    private const string KIND_REGISTRATION = 'registration';

    public function __construct(
        private readonly RecordPaymentReversedForRegistration $action,
    ) {}

    public function handle(OrderLineReversed $event): void
    {
        if ($event->kind !== self::KIND_REGISTRATION) {
            return;
        }

        $this->action->handle(
            registrationId: $event->refId,
            brandId: $event->brandId,
        );
    }
}
