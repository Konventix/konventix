<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * The payment lifecycle state — independent of `RegistrationStatus`
 * (ADR-0005, SPEC.md "Two independent status fields").
 *
 * `None` is the resting state for `settlement: free` registrations, which
 * never produce a Commerce order (ADR-0026) and therefore never have anything
 * to move this field. `Pending` and `Paid` are driven by Commerce's events:
 * `OrderPaid`/`OrderLineSettled` move a registration to `Paid`, and
 * `PaymentReversed`/`OrderLineReversed` move it back to `Pending` when settled
 * money leaves again (ADR-0057 point 5) — the confirmation and the quota it
 * holds stay put either way, because this field never implies
 * `RegistrationStatus`. `Overdue` is still catalogue only: `InvoiceOverdue`
 * has no consumer here yet (TODO.md Phase 2 backlog).
 */
enum PaymentStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
}
