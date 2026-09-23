import { trans } from 'laravel-vue-i18n';

/**
 * The orders console's shared reversal vocabulary (ADR-0057 point 8): the
 * index list and the order detail page both show reversals, so the wording
 * and the "which deadline counts" rule live here rather than twice.
 *
 * The shapes are the generated types of `OrderReversalData` and
 * `UnappliedPaymentData` (ADR-0009) — the Data class is the single source,
 * and neither page restates it by hand.
 */
export type OrderReversal = Konventix.Commerce.Data.OrderReversalData;
export type UnappliedPayment = Konventix.Commerce.Data.UnappliedPaymentData;

/**
 * Built per call rather than at import time: the catalog is loaded after
 * the modules are imported (ADR-0068). Order and reversal *status* labels
 * live in `lib/statusLabels.ts`, which the public pages share.
 */
export function reversalKindLabel(
    kind: Konventix.Commerce.Enums.ReversalKind,
): string {
    const labels: Record<Konventix.Commerce.Enums.ReversalKind, string> = {
        return: trans('Return'),
        dispute: trans('Dispute'),
        external_refund: trans('Dashboard refund'),
        // A refund made here, in the console (#247); the row exists to carry
        // the provider's own reference for it.
        refund: trans('Refund'),
    };

    return labels[kind] ?? kind;
}

/**
 * Whether the console still offers to book a payment by hand — a bank
 * transfer the organizer received, or money taken at the door (ADR-0018).
 *
 * `payment_reversed` belongs here beside `pending` (ADR-0057 point 3): the
 * sale stands and the amount is owed again, and `BookManualPayment` settles
 * that status exactly as it settles a pending one. Deliberately not a
 * `!paidAt` test — `RecordPaymentReversal` keeps `paid_at` on purpose (the
 * order *was* paid), so that test hid the button for every reversed order
 * and left the manual repayment path unreachable in the console.
 */
export function canBookPayment(status: string): boolean {
    return status === 'pending' || status === 'payment_reversed';
}

/**
 * The day the money is owed back by — the deadline of the newest final
 * reversal (ADR-0057 point 4). An open dispute has none: the provider still
 * holds the money and nothing is owed yet.
 *
 * Takes the rows rather than an `OrderRow`, so the order detail page — which
 * has the reversals but no table row — asks the same question of the same
 * code.
 */
export function repaymentDeadline(reversals: OrderReversal[]): string | null {
    return (
        reversals.find(
            (reversal) =>
                reversal.status === 'final' && reversal.repayDueOn !== null,
        )?.repayDueOn ?? null
    );
}
