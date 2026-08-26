<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * The capability catalogue this module owns (ADR-0013, SPEC.md
 * "Capabilities"). This is the source of truth for what Commerce can grant;
 * role → permission assignment is per-brand data, not code.
 *
 * `BookManualPayment` and `ManageSettlementAccount` are deliberately separate
 * cases: one is day-to-day treasury work, the other changes where a brand's
 * money goes (SPEC.md "Capabilities").
 *
 * Picked up by `permissions:sync` (ADR-0013); add a case and re-sync — never
 * create permission rows by hand.
 */
enum Permission: string
{
    case ViewOrders = 'commerce.view-orders';
    case ViewInvoices = 'commerce.view-invoices';
    case IssueInvoices = 'commerce.issue-invoices';
    case CancelInvoices = 'commerce.cancel-invoices';
    case BookManualPayment = 'commerce.book-manual-payment';
    case IssueRefunds = 'commerce.issue-refunds';
    case IssueCreditNotes = 'commerce.issue-credit-notes';
    case RunDunning = 'commerce.run-dunning';
    case ManageVouchers = 'commerce.manage-vouchers';
    case ManageSettlementAccount = 'commerce.manage-settlement-account';
    case ExportBookkeeping = 'commerce.export-bookkeeping';
}
