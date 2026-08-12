<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Data\BillingPartyData;
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
    ): Invoice {
        return DB::transaction(function () use ($order, $billingParty, $seller, $termsDays): Invoice {
            $issuedOn = now()->toDateString();

            $invoice = new Invoice([
                'order_id' => $order->id,
                'invoice_number' => $this->drawInvoiceNumber->handle($order->brand_id),
                'issued_on' => $issuedOn,
                'due_on' => now()->addDays($termsDays)->toDateString(),
                'currency' => $order->currency,
                'total_net' => $order->total_net,
                'total_tax' => $order->total_tax,
                'total_gross' => $order->total_gross,
                'tax_treatment' => $order->tax_treatment,
                'price_display' => $order->price_display,
                'billing_party' => $billingParty,
                'seller' => $seller,
            ]);
            // brand_id from the order, not ambient context — issuance may run
            // outside any resolved brand (queued replay, operator host).
            $invoice->brand_id = $order->brand_id;
            $invoice->save();

            event(new InvoiceIssued(
                invoiceId: $invoice->id,
                orderId: $order->id,
                brandId: $order->brand_id,
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
