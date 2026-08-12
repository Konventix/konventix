<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * The payment lifecycle state — independent of `RegistrationStatus`
 * (ADR-0005, SPEC.md "Two independent status fields").
 *
 * `None` is the resting state for `settlement: free` registrations, which
 * never produce a Commerce order (ADR-0026) and therefore never have anything
 * to move this field. `Pending`/`Paid`/`Overdue` are driven by Commerce's
 * events (`OrderPaid`, `InvoiceOverdue`, ...) — not wired yet, TODO.md Phase 2
 * backlog.
 */
enum PaymentStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
}
