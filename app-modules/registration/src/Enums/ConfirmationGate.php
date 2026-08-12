<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * Decouples confirmation from payment (ADR-0005): registration status and
 * payment status are two independent fields, and this axis decides which
 * event flips a `Registration` to `Confirmed`.
 *
 * - `OnSubmit` — confirmed the moment `SubmitRegistration` runs (Phase 1 only
 *   auto-confirms this for `settlement: free`; see `SubmitRegistration`).
 * - `OnApproval` — confirmed when `ApproveRegistration` runs.
 * - `OnPayment` — confirmed when Commerce's `OrderPaid` arrives. Not wired
 *   yet (TODO.md Phase 2 backlog): a type with this gate stays `Submitted`/
 *   `Approved` and never auto-confirms in Phase 1.
 */
enum ConfirmationGate: string
{
    case OnSubmit = 'on_submit';
    case OnApproval = 'on_approval';
    case OnPayment = 'on_payment';
}
