<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Events\RegistrationPaymentStatusChanged;
use Konventix\Registration\Models\Registration;

/**
 * The inverse of `RecordOrderPaidForRegistration` (ADR-0057 point 5): money
 * that was already settled left again, so the receivable revives — but the
 * seat does not move. `payment_status` goes back to `Pending` and
 * `RegistrationPaymentStatusChanged` says so; `status` and the quota units
 * the registration holds are deliberately untouched. ADR-0005's two
 * independent fields exist for exactly this case: a bank error two days
 * before the event does not throw a confirmed person out. Only the
 * repayment deadline does that, and it arrives as `OrderCancelled` with
 * reason `reversal_unpaid` (`CancelRegistrationOnOrderUndone`).
 *
 * Takes scalars, not the Commerce event object — the thin
 * `RecordReversalOnPaymentReversed` / `RecordReversalOnOrderLineReversed`
 * listeners unpack it, so "uses only the event payload" stays a structural
 * property. Registration never reads a `payment_reversals` row (ADR-0004).
 *
 * Idempotent: only a registration that is actually `Paid` has anything to
 * reverse, so a replayed `PaymentReversed` — or a `PaymentReversed` and its
 * per-line `OrderLineReversed` twin reaching the same registration — changes
 * nothing on the second run. `withoutBrandScope()` scoped to the payload's
 * own `brandId` (ADR-0010's named escape hatch): the lookup must hold
 * regardless of which brand is ambient when a queued replay runs.
 */
final class RecordPaymentReversedForRegistration
{
    public function handle(string $registrationId, string $brandId): ?Registration
    {
        return DB::transaction(function () use ($registrationId, $brandId): ?Registration {
            /** @var Registration|null $registration */
            $registration = Registration::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->whereKey($registrationId)
                ->lockForUpdate()
                ->first();

            if ($registration === null || $registration->payment_status !== PaymentStatus::Paid) {
                return $registration;
            }

            $registration->forceFill(['payment_status' => PaymentStatus::Pending])->save();

            event(new RegistrationPaymentStatusChanged(
                registrationId: $registration->id,
                brandId: $registration->brand_id,
                previousStatus: PaymentStatus::Paid->value,
                status: PaymentStatus::Pending->value,
                changedAt: now()->toIso8601String(),
            ));

            return $registration;
        });
    }
}
