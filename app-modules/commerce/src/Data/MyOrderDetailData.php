<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One order on its own page, `/my/orders/{order}` (ADR-0009, ADR-0044;
 * bugfix round 2026-09-21, Task 3) — where every hosted checkout lands
 * afterwards, whichever way it ended.
 *
 * `MyOrderData`'s fields exactly, so the list and the page can never name
 * a different pay link or a different invoice, plus what a landing page
 * has to say that a list row does not:
 *
 * - `totalNet`/`totalTax` beside the gross — the same three figures the
 *   pay pages print;
 * - `paymentStatus`, the *latest* attempt's state, which is what decides
 *   between "Payment received", "Waiting for the payment confirmation from
 *   the provider" and "Pay now" on a page reached from a provider's return
 *   URL. Every adapter takes one URL for success, cancel and failure by
 *   contract (`ProvidesHostedCheckout`), so the page branches on the
 *   order's state, never on the way it was reached — and the page never
 *   decides payment truth, it displays what the webhook has written;
 * - `paidAt`, an ISO-8601 instant, for the paid banner;
 * - `billingParty`, who the invoice addresses — null on an order booked by
 *   hand or placed before ADR-0048;
 * - `repayDueOn`, a calendar date (ADR-0057 point 4) for a reversed order,
 *   null for every other status.
 */
#[TypeScript]
final class MyOrderDetailData extends Data
{
    /**
     * @param  list<MyOrderLineData>  $lines
     * @param  list<MyCreditNoteData>  $creditNotes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $placedAt,
        public readonly string $settlesType,
        public readonly string $settlesId,
        public readonly string $settlesLabel,
        public readonly OrderStatus $status,
        public readonly string $currency,
        public readonly string $totalNet,
        public readonly string $totalTax,
        public readonly string $totalGross,
        public readonly ?string $invoiceNumber,
        public readonly bool $invoicePaid,
        public readonly ?string $payUrl,
        public readonly ?string $documentUrl,
        public readonly array $lines,
        public readonly array $creditNotes,
        public readonly ?PaymentStatus $paymentStatus,
        public readonly ?string $paidAt,
        public readonly ?BillingPartyData $billingParty,
        public readonly ?string $repayDueOn,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md). Built on
     * `MyOrderData::ofOrder()` rather than beside it, so the signed invoice
     * link, the pay link and the credit-note links are minted by exactly one
     * piece of code.
     *
     * @param  list<CreditNote>  $creditNotes
     */
    public static function ofOrder(
        Order $order,
        ?Invoice $invoice,
        array $creditNotes,
        ?Payment $latestPayment,
        ?string $repayDueOn,
    ): self {
        $summary = MyOrderData::ofOrder($order, $invoice, $creditNotes);

        return new self(
            id: $summary->id,
            placedAt: $summary->placedAt,
            settlesType: $summary->settlesType,
            settlesId: $summary->settlesId,
            settlesLabel: $summary->settlesLabel,
            status: $summary->status,
            currency: $summary->currency,
            totalNet: $order->total_net,
            totalTax: $order->total_tax,
            totalGross: $summary->totalGross,
            invoiceNumber: $summary->invoiceNumber,
            invoicePaid: $summary->invoicePaid,
            payUrl: $summary->payUrl,
            documentUrl: $summary->documentUrl,
            lines: $summary->lines,
            creditNotes: $summary->creditNotes,
            paymentStatus: $latestPayment?->status,
            paidAt: $order->paid_at?->toIso8601String(),
            billingParty: $order->billing_party,
            repayDueOn: $repayDueOn,
        );
    }
}
