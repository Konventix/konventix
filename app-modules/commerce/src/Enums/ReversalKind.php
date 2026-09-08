<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * What kind of reversal took the money back (ADR-0057 point 1). Three
 * kinds, two moments: `Return` and `ExternalRefund` are final the moment
 * they arrive, `Dispute` opens and is decided later.
 */
enum ReversalKind: string
{
    /**
     * Final on arrival: a SEPA Rücklastschrift, a Mollie chargeback
     * (`chb_…`), Unzer's `chargeback` state, and a Stripe dispute that is
     * born `lost` — Stripe's shape for a debit return, where no evidence
     * can be filed and the dispute closes as it opens.
     */
    case Return = 'return';

    /**
     * Open on arrival: the provider withholds the money and decides later
     * (Stripe `charge.dispute.created` in any status but `lost`). An open
     * dispute produces the operator notice and the console entry and
     * nothing else — the money may come back, and the person may
     * legitimately be at the event meanwhile. Evidence is filed in the
     * provider's dashboard, which the organizer keeps (ADR-0039 point 5).
     */
    case Dispute = 'dispute';

    /**
     * A refund the operator made in the provider's dashboard instead of
     * through `RefundOrder`. Final on arrival, but its reaction is the
     * *refund's*, not the receivable's: the sale is undone exactly as if
     * `RefundOrder` had run minus the provider call. ADR-0037's check-in
     * guard cannot run after the fact, which is why the path logs a
     * warning saying so.
     */
    case ExternalRefund = 'external_refund';
}
