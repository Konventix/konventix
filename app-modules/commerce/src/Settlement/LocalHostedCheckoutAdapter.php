<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * A stand-in for the `null` provider that CAN host a checkout, used only in
 * `local`/`testing` (`CommerceServiceProvider::registerBindings()`) so a
 * brand with no PSP connected — a k6 rush against a seeded demo brand
 * (ADR-0047 point 6), a manual click-through on a laptop — can still exercise
 * `PlaceOrderFromCart`'s full path. `NullSettlementAdapter`'s own docblock
 * (mirroring `SettlementAdapter`'s: "`manual` and `null` deliberately do
 * not [implement `ProvidesHostedCheckout`]") is production truth and stays
 * exactly that in every real environment — this class never binds outside
 * `local`/`testing`, so a brand that genuinely has no PSP still cannot sell
 * through the shop in production, which is the correct, load-bearing
 * refusal `CartCheckoutRefusedException::noOnlinePayment()` exists for.
 *
 * `markOrderPaid()`/`refund()` are the same deliberate no-op as
 * `NullSettlementAdapter` — there is still no PSP on the other end of this,
 * only a checkout that "starts". `startCheckout()` records a pending
 * `Payment` like every other adapter (a rush's `Order` count is read off
 * this, not off a payment that never happened) and sends the payer straight
 * back to `/my/orders` — there is no hosted page to redirect to.
 */
final class LocalHostedCheckoutAdapter implements ProvidesHostedCheckout, SettlementAdapter
{
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return $order;
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): ?string {
        // Deliberate no-op — see class docblock. No PSP, no reference (#247).
        return null;
    }

    public function startCheckout(
        SettlementAccount $account,
        Order $order,
        string $returnUrl,
    ): CheckoutRedirect {
        $payment = new Payment([
            'order_id' => $order->id,
            'settlement_account_id' => $account->id,
            'provider' => SettlementAccount::PROVIDER_NULL,
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_gross,
            'currency' => $order->currency,
        ]);
        // brand_id from the order, not ambient context (ADR-0010): checkout
        // may start from a queue or the public brand host alike.
        $payment->forceFill(['brand_id' => $order->brand_id])->save();

        return new CheckoutRedirect(paymentId: $payment->id, url: route('commerce.mine.index', absolute: false));
    }

    /**
     * There is no PSP behind this adapter, so nothing to ask (#231): a
     * `local`/`testing` checkout that was never paid is exactly what the
     * sweep should cancel.
     */
    public function hostedPaymentState(Payment $payment): HostedPaymentState
    {
        return HostedPaymentState::Unanswerable;
    }

    public function defaultCheckoutLabel(): string
    {
        return 'Test checkout (no PSP)';
    }
}
