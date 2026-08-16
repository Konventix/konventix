<?php

declare(strict_types=1);

namespace Konventix\Commerce\Bookkeeping;

use Carbon\CarbonInterface;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\BookkeepingSettings;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Platform\Models\Brand;

/**
 * Collects one operator's exportable documents for a period (ADR-0040):
 * every invoice and credit note issued by any of the operator's brands —
 * D-COM-05's "all brands of an operator as one file" — with postings
 * split into tax buckets.
 *
 * Bucket arithmetic is bcmath and never invents cents: an invoice's
 * buckets are its snapshotted line sums; a partial credit note pro-rates
 * the invoice's split and the last bucket absorbs the rounding remainder,
 * so buckets always sum to the document total the archive shows.
 *
 * Reads escape BrandScope explicitly (ADR-0010): the export runs on the
 * operator admin host, where the scope hardens to zero rows.
 */
final class CollectDocumentJournal
{
    /**
     * @return list<JournalEntry>
     */
    public function handle(
        string $operatorId,
        CarbonInterface $from,
        CarbonInterface $to,
        BookkeepingSettings $settings,
    ): array {
        $brandNames = Brand::query()
            ->where('operator_id', $operatorId)
            ->pluck('name', 'id');

        $brandIds = $brandNames->keys()->all();

        $entries = [];

        $invoices = Invoice::withoutBrandScope()
            ->whereIn('brand_id', $brandIds)
            ->whereBetween('issued_on', [$from, $to])
            ->orderBy('issued_on')
            ->orderBy('invoice_number')
            ->get();

        foreach ($invoices as $invoice) {
            $entries[] = new JournalEntry(
                kind: JournalEntry::KIND_INVOICE,
                number: $invoice->invoice_number,
                issuedOn: $invoice->issued_on,
                brandName: (string) $brandNames->get($invoice->brand_id),
                buyerName: $invoice->billing_party->displayName(),
                countryCode: $invoice->billing_party->countryCode,
                vatId: $invoice->billing_party->vatId,
                treatment: $invoice->tax_treatment,
                currency: $invoice->currency,
                totalNet: $invoice->total_net,
                totalTax: $invoice->total_tax,
                totalGross: $invoice->total_gross,
                buckets: $this->invoiceBuckets($invoice, $settings),
            );
        }

        $creditNotes = CreditNote::withoutBrandScope()
            ->whereIn('brand_id', $brandIds)
            ->whereBetween('issued_on', [$from, $to])
            ->orderBy('issued_on')
            ->orderBy('credit_note_number')
            ->get();

        foreach ($creditNotes as $creditNote) {
            $entries[] = new JournalEntry(
                kind: JournalEntry::KIND_CREDIT_NOTE,
                number: $creditNote->credit_note_number,
                issuedOn: $creditNote->issued_on,
                brandName: (string) $brandNames->get($creditNote->brand_id),
                buyerName: $creditNote->billing_party->displayName(),
                countryCode: $creditNote->billing_party->countryCode,
                vatId: $creditNote->billing_party->vatId,
                treatment: $creditNote->tax_treatment,
                currency: $creditNote->currency,
                totalNet: $creditNote->total_net,
                totalTax: $creditNote->total_tax,
                totalGross: $creditNote->total_gross,
                buckets: $this->creditNoteBuckets($creditNote, $settings),
            );
        }

        usort($entries, fn (JournalEntry $a, JournalEntry $b): int => [$a->issuedOn, $a->number] <=> [$b->issuedOn, $b->number]);

        return $entries;
    }

    /**
     * An invoice's buckets are its snapshotted order-line sums, grouped by
     * tax class. Reverse charge and §19 collapse to one bucket: the
     * treatment overrides any declared class (ADR-0030) and the whole
     * gross posts to that treatment's account.
     *
     * @return list<array{account: string, gross: numeric-string}>
     */
    private function invoiceBuckets(Invoice $invoice, BookkeepingSettings $settings): array
    {
        if ($invoice->tax_treatment !== TaxTreatment::Standard) {
            return [[
                'account' => $settings->revenueAccountFor($invoice->tax_treatment, TaxClass::Standard),
                'gross' => $invoice->total_gross,
            ]];
        }

        $byClass = OrderLine::withoutBrandScope()
            ->where('order_id', $invoice->order_id)
            ->orderBy('position')
            ->get()
            ->groupBy(fn (OrderLine $line): string => $line->tax_class->value);

        if ($byClass->isEmpty()) {
            return [[
                'account' => $settings->revenue_standard,
                'gross' => $invoice->total_gross,
            ]];
        }

        $buckets = [];

        foreach ($byClass as $class => $lines) {
            $gross = '0.00';

            foreach ($lines as $line) {
                $gross = bcadd($gross, $line->line_gross, 2);
            }

            $buckets[] = [
                'account' => $settings->revenueAccountFor(TaxTreatment::Standard, TaxClass::from($class)),
                'gross' => $gross,
            ];
        }

        return $buckets;
    }

    /**
     * A credit note posts the same buckets as its invoice, scaled to its
     * own total — the exact proportion its recorded tax split already uses
     * (TODO.md notes partials reuse the invoice's split). The last bucket
     * absorbs the rounding remainder so the rows sum to the credit note.
     *
     * @return list<array{account: string, gross: numeric-string}>
     */
    private function creditNoteBuckets(CreditNote $creditNote, BookkeepingSettings $settings): array
    {
        if ($creditNote->tax_treatment !== TaxTreatment::Standard) {
            return [[
                'account' => $settings->revenueAccountFor($creditNote->tax_treatment, TaxClass::Standard),
                'gross' => $creditNote->total_gross,
            ]];
        }

        $invoice = $creditNote->invoice_id === null
            ? null
            : Invoice::withoutBrandScope()->find($creditNote->invoice_id);

        if ($invoice === null || bccomp($invoice->total_gross, '0.00', 2) === 0) {
            return [[
                'account' => $settings->revenue_standard,
                'gross' => $creditNote->total_gross,
            ]];
        }

        $invoiceBuckets = $this->invoiceBuckets($invoice, $settings);

        if (count($invoiceBuckets) === 1) {
            return [[
                'account' => $invoiceBuckets[0]['account'],
                'gross' => $creditNote->total_gross,
            ]];
        }

        $buckets = [];
        $allocated = '0.00';
        $last = count($invoiceBuckets) - 1;

        foreach ($invoiceBuckets as $index => $bucket) {
            if ($index === $last) {
                $gross = bcsub($creditNote->total_gross, $allocated, 2);
            } else {
                // share = cn_total * bucket_gross / invoice_total, half-up.
                $gross = bcdiv(
                    bcmul($creditNote->total_gross, $bucket['gross'], 4),
                    $invoice->total_gross,
                    2,
                );
                $allocated = bcadd($allocated, $gross, 2);
            }

            $buckets[] = ['account' => $bucket['account'], 'gross' => $gross];
        }

        return $buckets;
    }
}
