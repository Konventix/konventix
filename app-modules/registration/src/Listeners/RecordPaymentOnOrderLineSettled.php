<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Registration\Actions\RecordOrderPaidForRegistration;

/**
 * The trigger for `confirmation_gate: on_payment` on a cart checkout
 * (ADR-0047, Task 11) — the counterpart to `RecordPaymentOnOrderPaid` for
 * an order that settles more than one thing at once. `OrderLineSettled` is
 * emitted only for a `settles_type: 'cart'` order (its own docblock says
 * why), so this listener never fires for the direct registration orders
 * `RecordPaymentOnOrderPaid` already confirms — the two listeners partition
 * on which event Commerce chose to emit, never on a flag either one checks.
 *
 * `Konventix\Commerce\Events\OrderLineSettled` is the one Commerce class
 * this listener imports, allowed because events are the public contract
 * between modules (ADR-0004). A `product`-kind line is Commerce's own
 * business and is ignored here.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderPaid`.
 */
final class RecordPaymentOnOrderLineSettled
{
    private const string KIND_REGISTRATION = 'registration';

    public function __construct(
        private readonly RecordOrderPaidForRegistration $action,
    ) {}

    public function handle(OrderLineSettled $event): void
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
