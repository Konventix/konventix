<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Registration\Actions\RecordPaymentReversedForRegistration;

/**
 * The mirror of `RecordPaymentOnOrderPaid` (ADR-0057 point 5): a settled
 * payment left again, so the registration's `payment_status` goes back to
 * `pending` while its confirmation and its quota stay exactly where they
 * are. Registered in `RegistrationServiceProvider::registerListeners()`.
 *
 * `Konventix\Commerce\Events\PaymentReversed` is the one Commerce class this
 * listener imports, allowed because events are the public contract between
 * modules (ADR-0004) — the reversal row itself is Commerce's and is never
 * read here.
 *
 * Orders settle things other than registrations (`settles_type` is an open
 * domain string), so anything else is ignored — including
 * `settles_type: 'cart'`, whose registration lines arrive as
 * `OrderLineReversed` instead, exactly the way `OrderLineSettled` partitions
 * the settlement path. The guard therefore needs no dedicated cart branch.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderPaid`.
 */
final class RecordReversalOnPaymentReversed
{
    private const string SETTLES_REGISTRATION = 'registration';

    public function __construct(
        private readonly RecordPaymentReversedForRegistration $action,
    ) {}

    public function handle(PaymentReversed $event): void
    {
        if ($event->settlesType !== self::SETTLES_REGISTRATION) {
            return;
        }

        $this->action->handle(
            registrationId: $event->settlesId,
            brandId: $event->brandId,
        );
    }
}
