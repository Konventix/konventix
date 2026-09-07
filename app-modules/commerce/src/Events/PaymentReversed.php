<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Money that was already settled left again: a Rücklastschrift, a lost
 * dispute, or an operator refund made outside `RefundOrder` (ADR-0057
 * point 2, "`PaymentReversed` is the cross-domain contract"). Emitted once,
 * from `Konventix\Commerce\Actions\RecordPaymentReversal`, when a reversal
 * becomes final — a `return` on arrival, a `dispute` on loss,
 * `external_refund` on arrival — never for an open or a withdrawn dispute,
 * which the `payment_reversals` row and the operator notice carry alone.
 *
 * `settlesType`/`settlesId` are carried so Registration can react without a
 * lookup, exactly as `OrderPaid` and `OrderRefunded` do. Registration
 * consumes this event to set `payment_status` back to `pending` while
 * leaving `status` (and the held quota) alone (ADR-0057 point 5).
 * **Admission never listens to this event** — ADR-0057 point 6 forbids the
 * door asking "is it paid?"; Admission reacts only to
 * `RegistrationCancelled`.
 *
 * `kind` is the reversal's own — `return`, `dispute` or `external_refund` —
 * and `settlementMethod`/`settlementAccountId` name the provider and
 * account the money left through, mirroring `OrderPaid`'s settlement
 * fields. `repayDueOn` is the deadline (ADR-0057 point 4) after which the
 * unpaid-reversal sweep cancels the order; null only where the amount or
 * kind gives no deadline to compute.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class PaymentReversed
{
    public function __construct(
        public string $reversalId,
        public string $paymentId,
        public string $orderId,
        public string $brandId,
        public string $settlesType,
        public string $settlesId,
        public string $kind,
        public string $amount,
        public string $currency,
        public string $settlementMethod,
        public ?string $settlementAccountId,
        public string $providerReference,
        public ?string $reasonCode,
        public ?string $repayDueOn,
        public string $reversedAt,
    ) {}
}
