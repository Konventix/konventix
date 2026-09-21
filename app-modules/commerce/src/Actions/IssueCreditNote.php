<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Exceptions\CreditNoteExceedsInvoiceException;
use Konventix\Commerce\Exceptions\CreditNoteRequiresInvoiceException;
use Konventix\Commerce\Jobs\GenerateCreditNoteDocument;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;

/**
 * Issues a credit note against an order — the only way to correct an issued
 * invoice, which is immutable (ADR-0031).
 *
 * Three callers, distinguished by `$reasonCode`: a refund (ADR-0037), a
 * post-invoice transfer to a different billing party (ADR-0036), and the
 * cancellation of an order whose reversed payment went unpaid (ADR-0057
 * point 4). The document is identical; what differs is what happens next,
 * which is why the reason travels on the event rather than being inferred
 * by consumers.
 *
 * **Requires an invoice.** A credit note is the correction of an invoice
 * (ADR-0031); an order that was never invoiced — an `instant`-settled one
 * paid at checkout — has no document to correct and no party snapshot to
 * name, so crediting it would produce a legal document that cannot say who it
 * is addressed to. Refunding such an order is money moving without paperwork,
 * which `RefundOrder` handles by simply not issuing one.
 *
 * Parties are snapshotted from the invoice, never read live, for the same
 * reason the invoice snapshotted them: the document must read correctly
 * years later.
 *
 * **Amounts are the caller's, not the invoice's** (#7). Until D-REG-15 this
 * Action copied the invoice's `total_net` and `total_tax` and overrode only
 * the gross, which is correct for a whole credit note and false for every
 * other: a partial credit note then printed the full invoice's net and tax
 * beside a smaller gross, and `CreditNoteDocumentRenderer` derives the VAT
 * rate it prints from exactly those two numbers — so the document stated a
 * rate nobody had ever charged. A caller that credits part of an invoice
 * now passes all three amounts, computed where the tax class and rate
 * actually are (`RefundUnitsOnRegistrationQuantityReduced` takes them off
 * the order line and `TaxCalculator`'s own formula). Passing none keeps the
 * whole-invoice behaviour unchanged.
 *
 * **The sum of an invoice's credit notes never exceeds its gross** (#7's
 * third criterion). Partial notes make that reachable — the order stays
 * `paid`, so nothing above refuses a second one — and a credit note cannot
 * be corrected afterwards (ADR-0031), so the guard is here, inside the
 * transaction that draws the number.
 *
 * `$reductionKey` is D-REG-15's idempotency key: the reduction whose units
 * this note credits, unique per brand at the database. Null for every other
 * caller.
 *
 * The number is drawn inside this transaction so a rollback returns it unused.
 */
final class IssueCreditNote
{
    public const string REASON_REFUND = 'refund';

    public const string REASON_TRANSFER = 'transfer';

    /**
     * ADR-0057 point 4: the order was cancelled because a reversed payment
     * went unpaid past its deadline. Document and counter-document then sum
     * to zero and the money side — payment in, return out — is the PSP
     * statement's, which is why no credit note is issued for the reversal
     * itself. `CancelOrderForUnpaidReversal` is the only caller; the DATEV
     * export books it exactly as it books the other two (ADR-0040 books
     * documents, and this is one).
     */
    public const string REASON_CANCELLATION = 'cancellation';

    public function __construct(
        private readonly DrawCreditNoteNumber $drawNumber,
    ) {}

    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $totalGross = null,
        ?string $totalNet = null,
        ?string $totalTax = null,
        ?string $reductionKey = null,
    ): CreditNote {
        return DB::transaction(function () use ($order, $reasonCode, $reasonNote, $totalGross, $totalNet, $totalTax, $reductionKey): CreditNote {
            $invoice = Invoice::withoutBrandScope()
                ->where('brand_id', $order->brand_id)
                ->where('order_id', $order->id)
                ->latest('issued_on')
                ->first();

            if ($invoice === null) {
                throw CreditNoteRequiresInvoiceException::forOrder($order->id);
            }

            $gross = $totalGross ?? $invoice->total_gross;

            if (! is_numeric($gross)) {
                // A money amount that is not a number has no business
                // reaching a gapless-numbered legal document; every caller
                // computes one with bcmath, so this can only be a bug.
                throw new InvalidArgumentException(sprintf(
                    'Credit note amount [%s] for order [%s] is not a number.',
                    $gross,
                    $order->id,
                ));
            }

            $this->guardInvoiceNotOverCredited($invoice, $gross);

            $creditNote = new CreditNote([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'credit_note_number' => $this->drawNumber->handle($order->brand_id),
                'billing_party' => $invoice->billing_party,
                'seller' => $invoice->seller,
                // All three or none: a caller that names one amount names
                // them all, so net + tax always equals gross on the row the
                // document renders from (#7).
                'total_net' => $totalNet ?? $invoice->total_net,
                'total_tax' => $totalTax ?? $invoice->total_tax,
                'total_gross' => $gross,
                'tax_treatment' => $invoice->tax_treatment,
                'currency' => $order->currency,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'reduction_key' => $reductionKey,
                'issued_on' => now(),
            ]);
            // brand_id from the order, not ambient context (ADR-0010).
            $creditNote->forceFill(['brand_id' => $order->brand_id])->save();

            event(new CreditNoteIssued(
                creditNoteId: $creditNote->id,
                creditNoteNumber: $creditNote->credit_note_number,
                orderId: $order->id,
                invoiceId: $invoice->id,
                brandId: $order->brand_id,
                totalGross: (string) $creditNote->total_gross,
                currency: $creditNote->currency,
                reasonCode: $reasonCode,
                issuedAt: $creditNote->issued_on->toIso8601String(),
            ));

            // ADR-0048 point 2: a Gutschrift gets a document and a mail, like
            // an invoice — `afterCommit()` so the job never races the row it
            // reads (the same shape `IssueInvoiceOnOrderPaid` uses).
            GenerateCreditNoteDocument::dispatch($creditNote->id, $order->brand_id)->afterCommit();

            return $creditNote;
        });
    }

    /**
     * #7's third acceptance criterion: the credit notes already written
     * against this invoice plus the one about to be written must not come
     * to more than the invoice's own gross.
     *
     * Read inside the caller's transaction, which holds the order lock by
     * the time `RecordRefund` reaches this — that lock is what makes the
     * read-then-write safe against a second refund of the same order.
     *
     * bcmath at scale 2, like every other money comparison here: the
     * columns are `decimal(12,2)` and a float sum would refuse a legitimate
     * final cent or let one through.
     */
    /**
     * @param  numeric-string  $gross
     */
    private function guardInvoiceNotOverCredited(Invoice $invoice, string $gross): void
    {
        $alreadyCredited = '0.00';

        $existing = CreditNote::withoutBrandScope()
            ->where('brand_id', $invoice->brand_id)
            ->where('invoice_id', $invoice->id)
            ->get();

        foreach ($existing as $note) {
            $alreadyCredited = bcadd($alreadyCredited, $note->total_gross, 2);
        }

        $invoiceGross = $invoice->total_gross;

        if (bccomp(bcadd($alreadyCredited, $gross, 2), $invoiceGross, 2) === 1) {
            throw CreditNoteExceedsInvoiceException::forInvoice(
                $invoice->invoice_number,
                $gross,
                $alreadyCredited,
                $invoiceGross,
            );
        }
    }
}
