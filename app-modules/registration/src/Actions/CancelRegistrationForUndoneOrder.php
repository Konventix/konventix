<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Konventix\Registration\Models\Registration;

/**
 * The order behind a registration no longer stands, so the contractual act
 * ends with it (ADR-0057 point 5) — the answer to the SPEC's long-proposed
 * "`OrderCancelled` → decide whether the contractual act is cancelled too"
 * row. Cancels through `CancelRegistration`, which releases the quota
 * (`QuotaReleased`, so the waitlist promotes) and emits
 * `RegistrationCancelled`, whose mail Registration already owns (ADR-0048).
 *
 * Takes scalars, never a Commerce event or model: the listeners that call
 * this — `CancelRegistrationOnOrderUndone` for an order-level event and
 * `CancelRegistrationOnOrderLineCancelled` for a cart order's line — are the
 * only place allowed to import `Konventix\Commerce\Events\*` (ADR-0004), and
 * they decide *whether* the event undoes the sale. This Action does the
 * *how*, once, for all of them — the shape `CancelPendingRegistrationForCart`
 * already establishes for the cart-expiry path.
 *
 * Idempotent: a registration already past `CancelRegistration`'s legal
 * starting states — `Cancelled` or `Rejected` — is left alone, so a replayed
 * event does not release the quota a second time, and the Action's transition
 * guard never throws out of a queue worker. The legal set is read off
 * `CancelRegistration::LEGAL_FROM_STATES` rather than restated here, so the
 * two cannot drift apart.
 *
 * `withoutBrandScope()` scoped to the payload's own `brandId` (ADR-0010's
 * named escape hatch): the unpaid-reversal sweep runs from a console command
 * with no ambient brand.
 */
final class CancelRegistrationForUndoneOrder
{
    /**
     * Registration's own reason codes for an order that stopped standing,
     * free strings spelled the way `cart_expired` already is. They live here
     * rather than on either listener because both cancellation paths — the
     * order-level one and the cart line's — write the same one, and because
     * they stay distinct: the audit column has to say what actually happened,
     * and a receivable that was never repaid is not an operator handing the
     * money back.
     */
    public const string REASON_PAYMENT_REVERSED = 'payment_reversed';

    public const string REASON_ORDER_REFUNDED = 'order_refunded';

    /**
     * The direct `instant` order behind this registration was never paid
     * inside the payment window and the `commerce:expire-registration-orders`
     * sweep called it off (#131). Distinct from `payment_reversed` for the
     * reason the other two are distinct from each other: nobody ever paid
     * here, which is not a payer taking money back.
     */
    public const string REASON_PAYMENT_WINDOW_EXPIRED = 'payment_window_expired';

    public function __construct(
        private readonly CancelRegistration $cancelRegistration,
    ) {}

    /**
     * @param  string|null  $cancelledByUserId  null where nobody acted —
     *                                          ADR-0057 point 4's sweep, and every refund.
     */
    public function handle(
        string $brandId,
        string $registrationId,
        string $reasonCode,
        ?string $cancelledByUserId = null,
    ): ?Registration {
        /** @var Registration|null $registration */
        $registration = Registration::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereKey($registrationId)
            ->first();

        if ($registration === null) {
            return null;
        }

        if (! in_array($registration->status, CancelRegistration::LEGAL_FROM_STATES, true)) {
            return $registration;
        }

        return $this->cancelRegistration->handle(
            $registration,
            cancelledByUserId: $cancelledByUserId,
            reasonCode: $reasonCode,
        );
    }
}
