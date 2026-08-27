<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Data\PaymentSnapshotData;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Data\OperatorSellerData;

/**
 * Issues the invoice for an order (ADR-0031): draws the gapless number,
 * snapshots amounts and both parties, computes the due date from the
 * declared payment term, and emits `InvoiceIssued`. From this row's
 * creation on, nothing about it may change — the ZUGFeRD document is
 * rendered *from* this snapshot by `GenerateInvoiceDocument`, dispatched
 * after the surrounding transaction commits so a rollback never leaves an
 * orphaned archive file.
 *
 * Idempotent per order (ADR-0048): at most one invoice ever exists for a
 * given `order_id` — an invoice-settled order already has one from
 * placement, and `IssueInvoiceOnOrderPaid` must not create a second one
 * when the same order pays later, or when `OrderPaid` is replayed. The
 * order row is locked (`lockForUpdate`) *inside* the transaction, before
 * the existence check: `RecordPaymentSuccess::settleOrder()` already holds
 * this same lock for the `OrderPaid` path, but a manual booking
 * (`ManualSettlementAdapter::markOrderPaid()`) takes no lock of its own
 * before calling in, so two concurrent operator double-submits must
 * serialize here — the second call blocks until the first transaction
 * commits, then finds the invoice the first one just issued and returns
 * it, rather than both racing the existence check and drawing two numbers
 * for the same order.
 *
 * `$paid`, when given, is what turns this into ADR-0048's paid Rechnung:
 * the due date collapses to the issue date — the money already landed, so
 * there is nothing left to be due — and the payment snapshot is written
 * onto the row at the same moment as everything else, never as a later
 * edit (ADR-0031 immutability).
 */
final class IssueInvoice
{
    public function __construct(
        private readonly DrawInvoiceNumber $drawInvoiceNumber,
    ) {}

    public function handle(
        Order $order,
        BillingPartyData $billingParty,
        OperatorSellerData $seller,
        int $termsDays,
        ?PaymentSnapshotData $paid = null,
    ): Invoice {
        return DB::transaction(function () use ($order, $billingParty, $seller, $termsDays, $paid): Invoice {
            // Lock first, then check (see class docblock): a second,
            // concurrent call for the same order blocks here rather than
            // racing the existence check below.
            Order::withoutBrandScope()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = Invoice::withoutBrandScope()->where('order_id', $order->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $issuedOn = now()->toDateString();

            $attributes = [
                'order_id' => $order->id,
                'invoice_number' => $this->drawInvoiceNumber->handle($order->brand_id),
                'issued_on' => $issuedOn,
                // Paid at issuance: nothing is still owed, so the due date
                // is the issue date itself rather than a computed term.
                'due_on' => $paid !== null ? $issuedOn : now()->addDays($termsDays)->toDateString(),
                'currency' => $order->currency,
                'total_net' => $order->total_net,
                'total_tax' => $order->total_tax,
                'total_gross' => $order->total_gross,
                'tax_treatment' => $order->tax_treatment,
                'price_display' => $order->price_display,
                'billing_party' => $billingParty,
                'seller' => $seller,
            ];

            if ($paid !== null) {
                $attributes['paid_at'] = $paid->paidAt;
                $attributes['paid_via'] = $paid->paidVia;
                $attributes['paid_provider'] = $paid->provider;
                $attributes['payment_reference'] = $paid->paymentReference;
            }

            $invoice = new Invoice($attributes);
            // brand_id from the order, not ambient context — issuance may run
            // outside any resolved brand (queued replay, operator host).
            $invoice->brand_id = $order->brand_id;
            $invoice->save();

            event(new InvoiceIssued(
                invoiceId: $invoice->id,
                orderId: $order->id,
                brandId: $order->brand_id,
                settlesType: $order->settles_type,
                settlesId: $order->settles_id,
                invoiceNumber: $invoice->invoice_number,
                issuedOn: $invoice->issued_on->toDateString(),
                dueOn: $invoice->due_on->toDateString(),
                totalGross: $invoice->total_gross,
                currency: $invoice->currency,
            ));

            GenerateInvoiceDocument::dispatch($invoice->id, $invoice->brand_id)->afterCommit();

            return $invoice;
        });
    }
}
