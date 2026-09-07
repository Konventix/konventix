<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;

/**
 * ADR-0057 point 5, the answer to the SPEC's long-proposed "`OrderCancelled`
 * → decide whether the contractual act is cancelled too" row: yes, when the
 * order no longer stands at all.
 *
 * Two events say that, and both are handled here because the reaction is
 * identical — cancel through `CancelRegistration`, which releases the quota
 * (`QuotaReleased`, so the waitlist promotes) and emits
 * `RegistrationCancelled`, whose mail Registration already owns (ADR-0048):
 *
 * - `OrderCancelled` with reason `reversal_unpaid`: the repayment deadline
 *   of ADR-0057 point 4 passed and the `commerce:cancel-unpaid-reversals`
 *   sweep cancelled the order. Any other reason code is an order-level fact
 *   this module has not been told to act on, and is ignored.
 * - `OrderRefunded` with `whole: true`: the whole-order refund of ADR-0037,
 *   whose downstream half was left open there. `amountRefunded` alone cannot
 *   say whether a refund was whole, which is why the event carries the flag;
 *   a partial refund leaves the contractual act standing.
 *
 * The reversal itself never reaches here — `PaymentReversed` only revives the
 * receivable (`RecordReversalOnPaymentReversed`); the seat is kept until the
 * deadline. Registration never reads a reversal row (ADR-0004).
 *
 * `Konventix\Commerce\Events\OrderCancelled`/`OrderRefunded` are the Commerce
 * classes this listener imports, allowed because events are the public
 * contract between modules (ADR-0004). Registered twice in
 * `RegistrationServiceProvider::registerListeners()`, once per event, both
 * bound to this same method — the shape
 * `CancelPendingRegistrationOnCartLineGone` established.
 *
 * The registration is resolved here rather than in an Action of its own
 * because the state transition already has one: `CancelRegistration` owns
 * it, and this listener only decides *whether* it applies. `withoutBrandScope()`
 * scoped to the payload's own `brandId` (ADR-0010's named escape hatch): the
 * sweep runs from a console command with no ambient brand.
 *
 * Idempotent: a registration already `Cancelled` or `Rejected` is left alone,
 * so a replayed event does not release the quota a second time — which is
 * also what keeps `CancelRegistration`'s transition guard from throwing out
 * of a queue worker.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderPaid`.
 */
final class CancelRegistrationOnOrderUndone
{
    private const string SETTLES_REGISTRATION = 'registration';

    /**
     * The order-level reason that means "the reversed payment was never
     * repaid" (ADR-0057 point 4).
     */
    private const string REASON_REVERSAL_UNPAID = 'reversal_unpaid';

    /**
     * Registration's own reason codes, free strings spelled the way
     * `cart_expired` already is. They stay distinct because the audit column
     * they land in has to say what actually happened: a receivable that was
     * never repaid is not an operator handing the money back.
     */
    private const string REASON_PAYMENT_REVERSED = 'payment_reversed';

    private const string REASON_ORDER_REFUNDED = 'order_refunded';

    public function __construct(
        private readonly CancelRegistration $cancelRegistration,
    ) {}

    public function handle(OrderCancelled|OrderRefunded $event): void
    {
        if ($event->settlesType !== self::SETTLES_REGISTRATION) {
            return;
        }

        $reasonCode = $this->registrationReasonCode($event);

        if ($reasonCode === null) {
            return;
        }

        /** @var Registration|null $registration */
        $registration = Registration::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->whereKey($event->settlesId)
            ->first();

        if ($registration === null) {
            return;
        }

        if (in_array($registration->status, [RegistrationStatus::Cancelled, RegistrationStatus::Rejected], true)) {
            return;
        }

        $this->cancelRegistration->handle(
            $registration,
            // Null when the sweep cancelled rather than an operator, and
            // always null for a refund — ADR-0057 point 4's actor-less case.
            cancelledByUserId: $event instanceof OrderCancelled ? $event->cancelledByUserId : null,
            reasonCode: $reasonCode,
        );
    }

    /**
     * The reason code to cancel the registration with, or null when this
     * event does not undo the sale at all.
     */
    private function registrationReasonCode(OrderCancelled|OrderRefunded $event): ?string
    {
        return match (true) {
            $event instanceof OrderCancelled => $event->reasonCode === self::REASON_REVERSAL_UNPAID
                ? self::REASON_PAYMENT_REVERSED
                : null,
            default => $event->whole ? self::REASON_ORDER_REFUNDED : null,
        };
    }
}
