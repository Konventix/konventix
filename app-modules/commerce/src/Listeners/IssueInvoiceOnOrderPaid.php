<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\IssueInvoice;
use Konventix\Commerce\Data\PaymentSnapshotData;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Tax\GermanVat;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * ADR-0048: a paid order gets a Rechnung the moment it is paid. Registered
 * against `OrderPaid` in `CommerceServiceProvider::registerListeners()` —
 * before this, only an invoice-settled registration order (issued at
 * placement by `PlaceOrderFromSettlementRequest`) produced a document; a
 * card-paid registration and every cart order (ADR-0047) got none.
 *
 * `OrderPaid` is emitted from inside `RecordPaymentSuccess::settleOrder()`'s
 * own `DB::transaction()`, so this listener — and the nested transaction
 * `IssueInvoice` opens — join it: a rollback there rolls this back too.
 * `GenerateInvoiceDocument` is already dispatched `afterCommit()`, so the
 * ZUGFeRD archive and the mail only ever happen once the money is
 * genuinely recorded.
 *
 * Guard order matters: an order that no longer exists (impossible in
 * practice, but a replayed event should not blow up on it) is a no-op; one
 * with no `billing_party` predates ADR-0048 or was booked manually with no
 * billing information — logged, not guessed at; one whose gross exceeds
 * `GermanVat::requiresBillingAddress()`'s ceiling and whose billing party
 * carries no postal address (#4) is withheld the same way — an issued
 * invoice can never be corrected in place (ADR-0031), so "issue it, then
 * fix the address" is not an option the way it would be for an ordinary
 * data error; one that already has an invoice is either invoice-settled
 * (issued at placement) or a replayed `OrderPaid` — either way there is
 * nothing left to do, and `IssueInvoice` itself repeats this exact check
 * as a second line of defence.
 */
final class IssueInvoiceOnOrderPaid
{
    public function __construct(
        private readonly IssueInvoice $issueInvoice,
        private readonly OperatorSettings $operatorSettings,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::withoutBrandScope()->whereKey($event->orderId)->with('lines')->first();

        if ($order === null) {
            return;
        }

        if ($order->billing_party === null) {
            Log::warning('Order paid with no billing party; skipping invoice issuance.', [
                'order_id' => $order->id,
                'brand_id' => $event->brandId,
            ]);

            return;
        }

        // #4: above GermanVat's small-amount ceiling (§33 UStDV), §14 Abs. 4
        // UStG needs the recipient's postal address, and this order has
        // none — a `PlaceOrderFromCart` order placed after
        // `guardBillingAddress()` shipped cannot reach this state, but one
        // placed before it, or a registration order whose instant-settled
        // billing party never carried an address either, still can. The
        // Rechnung is withheld rather than issued incomplete (ADR-0031:
        // once issued it can never be corrected in place) —
        // `OrderAdminController::addBillingAddress()` is the operator's
        // retrofit, and it issues the invoice itself once the address
        // lands.
        if (GermanVat::requiresBillingAddress($order->total_gross) && ! $order->billing_party->hasPostalAddress()) {
            Log::warning('Order paid over the small-amount invoice threshold with no billing address; withholding invoice issuance.', [
                'order_id' => $order->id,
                'brand_id' => $event->brandId,
            ]);

            return;
        }

        $alreadyInvoiced = Invoice::withoutBrandScope()->where('order_id', $order->id)->exists();

        if ($alreadyInvoiced) {
            return;
        }

        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        $seller = $this->operatorSettings->sellerForBrand($event->brandId);

        $this->issueInvoice->handle(
            $order,
            $order->billing_party,
            $seller,
            termsDays: 0,
            paid: new PaymentSnapshotData(
                paidAt: $event->paidAt,
                provider: $event->settlementMethod,
                paymentReference: $payment?->external_id,
            ),
        );
    }
}
