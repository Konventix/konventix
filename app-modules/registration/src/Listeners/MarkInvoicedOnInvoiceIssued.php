<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Registration\Models\Registration;

/**
 * ADR-0036: the billing party freezes once the first invoice is issued.
 * Registration cannot read Commerce's invoices (ADR-0004), so it learns
 * the fact from `InvoiceIssued` and stamps `invoiced_at` — the guard
 * `UpdateBillingParty` checks. Idempotent: only the first issuance stamps.
 */
final class MarkInvoicedOnInvoiceIssued
{
    public function handle(InvoiceIssued $event): void
    {
        if ($event->settlesType !== 'registration') {
            return;
        }

        Registration::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->whereKey($event->settlesId)
            ->whereNull('invoiced_at')
            ->update(['invoiced_at' => now()]);
    }
}
