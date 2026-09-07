<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions\Concerns;

use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;

/**
 * "Did anyone actually attend on this order?", asked of Commerce's **own**
 * read model (ADR-0037, D-COM-15): this module must never query Admission's
 * tables, so `CheckedInAdmission` — projected from `AdmissionCheckedIn` — is
 * the only place the question can be answered.
 *
 * Two callers with two different answers to give. `RefundOrder` refuses the
 * refund outright (ADR-0037: someone attended, so returning the money is a
 * decision a human must make deliberately). The unpaid-reversal sweep
 * (ADR-0057 point 4) does not refuse anything — it *skips* the
 * cancellation and leaves the invoice as an open receivable, because
 * attendance happened and the money is owed. Extracted here so the two
 * share one definition of "checked in" rather than drifting apart.
 *
 * ADR-0037 accepts a known gap both inherit: an offline device reconciles
 * late (ADR-0033), so a check-in that has happened but not yet arrived is
 * invisible here.
 */
trait GuardsCheckedInAdmissions
{
    /**
     * The refund's answer: refuse, explicitly and reasoned.
     */
    private function guardNotCheckedIn(Order $order): void
    {
        $registrationId = $this->checkedInRegistrationId($order);

        if ($registrationId !== null) {
            throw RefundBlockedByCheckInException::forRegistration($registrationId);
        }
    }

    /**
     * The sweep's answer: which registration on this order was used, if any.
     *
     * Only registrations can be checked in. A direct registration order has
     * one to check; a cart order (ADR-0047) may hold several — every
     * `settles_kind: 'registration'` line names one, and any single
     * checked-in admission among them counts for the whole order the same
     * way, since both a cart refund and a cart cancellation are
     * whole-order (SPEC.md). An order settling neither shape has no
     * admissions and therefore nothing to find.
     */
    private function checkedInRegistrationId(Order $order): ?string
    {
        if ($order->settles_type === 'registration') {
            return CheckedInAdmission::registrationHasCheckIn($order->brand_id, $order->settles_id)
                ? $order->settles_id
                : null;
        }

        if ($order->settles_type !== 'cart') {
            return null;
        }

        $registrationIds = OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', 'registration')
            ->whereNotNull('settles_ref')
            ->pluck('settles_ref');

        foreach ($registrationIds as $registrationId) {
            if (CheckedInAdmission::registrationHasCheckIn($order->brand_id, $registrationId)) {
                return $registrationId;
            }
        }

        return null;
    }
}
