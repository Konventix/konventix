<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Commerce\Data\CreditNoteLineSelectionData;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Exceptions\CreditNoteExceedsInvoiceException;
use Konventix\Commerce\Exceptions\CreditNoteRequiresInvoiceException;
use Konventix\Commerce\Jobs\GenerateCreditNoteDocument;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\CreditNoteLine;
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
 * **A partial credit note names lines and quantities** (#7, D-0.8.2-1).
 * `$lines` is the whole shape of a partial credit note: the invoice lines
 * being credited and how many units of each. `ResolveCreditNoteLines`
 * computes the net, the VAT and the gross **per line** at each line's own
 * snapshotted rate (ADR-0017) through `TaxCalculator`, and those lines are
 * snapshotted onto `credit_note_lines` beside the totals they sum to.
 *
 * That replaces inheriting the invoice's allocation for a gross sum, which
 * was #7's defect and a bookkeeping one rather than a rounding one: on an
 * invoice mixing 19% and 7%, crediting the reduced-rate line produced the
 * invoice's blended split, and the document stated a VAT figure nobody had
 * charged. A gross alone cannot be un-blended, because it does not say
 * which line it came from.
 *
 * **Amounts may still be passed explicitly**, without lines. That is the
 * whole-invoice credit note — a full refund, an ADR-0036 transfer, ADR-0057
 * point 4's cancellation — which credits everything and needs no
 * allocation, and it is also how a caller that has already computed one
 * line's amounts states them (the D-REG-15 unit refund passes `$lines` now,
 * so nothing in the codebase takes that road any more). Passing neither
 * copies the invoice's totals, which is exact for a whole credit note and
 * for nothing else.
 *
 * **The sum of an invoice's credit notes never exceeds its gross** (#7's
 * third criterion), and **no line is credited beyond what was sold**.
 * Partial notes make both reachable — the order stays `paid`, so nothing
 * above refuses a second one — and a credit note cannot be corrected
 * afterwards (ADR-0031), so both guards are here, inside the transaction
 * that draws the number. The per-line one is the stricter of the two: an
 * invoice of 200.00 + 100.00 still has 100.00 of room after its first line
 * is credited whole, and without `credit_note_lines` as a ledger the second
 * note could spend that room on the same line again. Both refuse rather
 * than clamp: a silent clamp turns an operator's mistake into a wrong
 * document.
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
        private readonly ResolveCreditNoteLines $resolveLines,
    ) {}

    /**
     * `$lines` is D-0.8.2-1's line-and-quantity selection; when it is given
     * the three amounts are computed from it and any `$totalGross`/
     * `$totalNet`/`$totalTax` the caller also passed are ignored, because
     * the lines are the more specific statement of the same thing and two
     * sources of truth for one document's totals is how they come apart.
     *
     * @param  list<CreditNoteLineSelectionData>|null  $lines
     */
    public function handle(
        Order $order,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $totalGross = null,
        ?string $totalNet = null,
        ?string $totalTax = null,
        ?string $reductionKey = null,
        ?array $lines = null,
    ): CreditNote {
        return DB::transaction(function () use ($order, $reasonCode, $reasonNote, $totalGross, $totalNet, $totalTax, $reductionKey, $lines): CreditNote {
            $invoice = Invoice::withoutBrandScope()
                ->where('brand_id', $order->brand_id)
                ->where('order_id', $order->id)
                ->latest('issued_on')
                ->first();

            if ($invoice === null) {
                throw CreditNoteRequiresInvoiceException::forOrder($order->id);
            }

            // Resolved inside the transaction, and inside the order lock
            // `RecordRefund` is holding by the time a refund reaches here:
            // the per-line guard is a read-then-write, and the lock is what
            // makes it safe against a second credit note of the same line.
            // `RefundOrder` has already asked the same question before the
            // provider call, so a refusal there costs nothing; this call is
            // the authoritative one.
            $credited = $lines === null ? null : $this->resolveLines->handle($order, $lines);

            if ($credited !== null) {
                $totalNet = $credited->totalNet;
                $totalTax = $credited->totalTax;
                $totalGross = $credited->totalGross;
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
                // A credit note speaks the language of the invoice it
                // reverses (ADR-0068 §6), whoever makes the refund and in
                // whatever locale their request runs.
                'locale' => $invoice->locale,
            ]);
            // brand_id from the order, not ambient context (ADR-0010).
            $creditNote->forceFill(['brand_id' => $order->brand_id])->save();

            if ($credited !== null) {
                foreach ($credited->lines as $line) {
                    $row = new CreditNoteLine([
                        'credit_note_id' => $creditNote->getKey(),
                        ...$line->toRow(),
                    ]);
                    // Same reason as the credit note itself: the brand is
                    // the order's, never the ambient context (ADR-0010) —
                    // a refund is made from the operator host, which
                    // resolves none.
                    $row->forceFill(['brand_id' => $order->brand_id])->save();
                }
            }

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
