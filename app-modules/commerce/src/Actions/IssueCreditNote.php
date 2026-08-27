<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Exceptions\CreditNoteRequiresInvoiceException;
use Konventix\Commerce\Jobs\GenerateCreditNoteDocument;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;

/**
 * Issues a credit note against an order — the only way to correct an issued
 * invoice, which is immutable (ADR-0031).
 *
 * Two callers, distinguished by `$reasonCode`: a refund (ADR-0037) and a
 * post-invoice transfer to a different billing party (ADR-0036). The document
 * is identical; what differs is what happens next, which is why the reason
 * travels on the event rather than being inferred by consumers.
 *
 * **Requires an invoice.** A credit note is the correction of an invoice
 * (ADR-0031); an order that was never invoiced — an `instant`-settled one
 * paid at checkout — has no document to correct and no party snapshot to
 * name, so crediting it would produce a legal document that cannot say who it
 * is addressed to. Refunding such an order is money moving without paperwork,
 * which `RefundOrder` handles by simply not issuing one.
 *
 * Parties and amounts are snapshotted from the invoice, never read live, for
 * the same reason the invoice snapshotted them: the document must read
 * correctly years later.
 *
 * The number is drawn inside this transaction so a rollback returns it unused.
 */
final class IssueCreditNote
{
    public const string REASON_REFUND = 'refund';

    public const string REASON_TRANSFER = 'transfer';

    public function __construct(
        private readonly DrawCreditNoteNumber $drawNumber,
    ) {}

    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $totalGross = null,
    ): CreditNote {
        return DB::transaction(function () use ($order, $reasonCode, $reasonNote, $totalGross): CreditNote {
            $invoice = Invoice::withoutBrandScope()
                ->where('brand_id', $order->brand_id)
                ->where('order_id', $order->id)
                ->latest('issued_on')
                ->first();

            if ($invoice === null) {
                throw CreditNoteRequiresInvoiceException::forOrder($order->id);
            }

            $creditNote = new CreditNote([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'credit_note_number' => $this->drawNumber->handle($order->brand_id),
                'billing_party' => $invoice->billing_party,
                'seller' => $invoice->seller,
                'total_net' => $invoice->total_net,
                'total_tax' => $invoice->total_tax,
                'total_gross' => $totalGross ?? $invoice->total_gross,
                'tax_treatment' => $invoice->tax_treatment,
                'currency' => $order->currency,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
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
}
