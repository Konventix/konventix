<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Settlement\HostedPaymentProbe;
use Konventix\Commerce\Settlement\PaymentWindow;

/**
 * The sweep #131 asks for: a *direct* registration order — the one a brand
 * without the `commerce:shop` feature still places straight from an
 * `instant` settlement request (ADR-0047 point 3 hands only shop brands'
 * instant settlements to a cart) — never expired. `ExpireCarts` sweeps
 * carts and, since ADR-0047 point 4, checked-out carts whose order went
 * unpaid; nothing swept an order that never had a cart at all, so an
 * unpaid `instant` registration held its quota forever.
 *
 * **The window is the carts' own**, read off `Settlement\PaymentWindow`
 * rather than restated, so the sweep and the pay pages cannot drift: #131
 * asks for "the same window as carts", and the question both ask is the same
 * one — how long a payer gets between being sent to the PSP and the seat
 * coming back. That class is also what tells the payer the deadline exists
 * (whole-branch review finding 2).
 *
 * **Only orders nobody was ever invoiced for.** An `invoice`-settled
 * registration order is a receivable with payment terms (ADR-0031: the
 * document exists and stands); cancelling it an hour after it was issued
 * would undo a debt the payer has days to settle. The dunning ladder
 * (`RunDunningSweep`) owns that one. Filtered on the existence of an
 * `invoices` row rather than on a settlement column, because the order
 * carries no settlement kind — the invoice *is* the record that terms were
 * given.
 *
 * **And only orders that had a hosted payment window to miss** (fix round
 * 1). A sixty-minute deadline is the length of a PSP session, and it is
 * only defensible where there was one. A brand that has connected no
 * settlement account settles through the `manual` provider — `SettlementAccount`
 * calls that the default and "a legitimate permanent choice for organizers
 * who invoice" — so `pay/Show.vue` renders no pay button and tells the payer
 * their details arrive with the confirmation and the ticket follows once the
 * transfer has been received. A transfer takes days. Cancelling that order
 * after an hour would call off a sale against this application's own copy.
 *
 * The discriminator is therefore the brand's settlement accounts, and it is
 * exactly `CheckoutController::offeredAccounts()`'s predicate: at least one
 * live, connected account whose adapter implements `ProvidesHostedCheckout`.
 * That is precisely the population ADR-0047 point 4 reasons about — a cart
 * cannot reach `checked_out` without such an account, which is why
 * `ExpireCarts` never had to ask. Deliberately *not* "the order has a
 * `payments` row": a payer who reached the hosted page and closed the tab
 * before the provider created a session still holds a seat nobody can buy,
 * and that is the case #131 was filed about. Resolved once for the whole
 * sweep and applied as a `WHERE brand_id IN (…)`, so Postgres excludes a
 * bank-transfer brand's orders rather than this loop discarding them on
 * every run for ever (whole-branch review finding 4); the partial index
 * `orders_pending_registration_sweep_index` supports the rest.
 *
 * Cancelling emits `OrderCancelled` with reason
 * `payment_window_expired`, the event Registration already consumes
 * (`CancelRegistrationOnOrderUndone`) to release the quota. No
 * `OrderLineCancelled`: that one exists so a *cart* order can say which of
 * its several registrations it undid, and a direct order settles exactly
 * one thing, which `settlesId` names. No credit note either — an invoiced
 * order is never selected here, so there is no document to correct
 * (ADR-0031).
 *
 * **The provider is asked before an order with an open hosted payment is
 * cancelled (#231)**, exactly as in `ExpireCarts`: money at the PSP is
 * booked through `RecordPaymentSuccess` rather than cancelled, and a
 * provider that cannot answer keeps its order until the next run.
 *
 * A late payment against an order this sweep cancelled anyway behaves
 * exactly as it does after the cart sweep (#6): `RecordPaymentSuccess`
 * refuses to settle a cancelled order and records `PaymentUnapplied`
 * (ADR-0057 point 9) so the operator sees the money and decides.
 *
 * One transaction per order so one bad row never blocks the rest, and
 * every read escapes BrandScope explicitly (ADR-0010): this runs from a
 * console command, where no brand is resolved from a host. The status is
 * re-checked under the row lock — a payment may have succeeded between the
 * query and the lock.
 */
final class ExpireRegistrationOrders
{
    /**
     * The reason code `OrderCancelled` carries. Registration keys its
     * cancellation listeners on the reason, so this string is contract.
     */
    public const string REASON_PAYMENT_WINDOW_EXPIRED = 'payment_window_expired';

    /**
     * The same window a checked-out cart's order gets (#131: "dieselbe
     * Frist wie Warenkoerbe").
     */
    public const int WINDOW_MINUTES = PaymentWindow::MINUTES;

    private const string SETTLES_REGISTRATION = 'registration';

    public function __construct(
        private readonly PaymentWindow $paymentWindow,
        private readonly HostedPaymentProbe $probe,
        private readonly RecordPaymentSuccess $recordSuccess,
    ) {}

    /**
     * @return int the number of orders cancelled
     */
    public function handle(?string $brandId = null): int
    {
        $cancelled = 0;

        // Resolved once, ahead of the query, and applied *in SQL* (fix round
        // 2, whole-branch review finding 4). Filtering this in PHP meant a
        // bank-transfer brand's orders were streamed on every run, skipped,
        // and streamed again on the next one — they are never cancelled, so
        // they never leave the result set, and the sweep's working set grew
        // without bound. One query over `settlement_accounts` lets Postgres
        // exclude them instead.
        // Opens the run's provider-call budget (#231, fix round 1).
        $this->probe->startRun();

        $hostedBrandIds = $this->paymentWindow->brandIdsOfferingHostedCheckout();

        if ($hostedBrandIds === []) {
            return 0;
        }

        $query = Order::withoutBrandScope()
            ->whereIn('brand_id', $hostedBrandIds)
            ->where('settles_type', self::SETTLES_REGISTRATION)
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes(self::WINDOW_MINUTES))
            ->whereNotExists(function ($sub): void {
                // Both table names resolved through their models, so a
                // rename cannot leave half of this subquery behind.
                $invoices = (new Invoice)->getTable();
                $orders = (new Order)->getTable();

                $sub->select(DB::raw('1'))
                    ->from($invoices)
                    ->whereColumn($invoices.'.order_id', $orders.'.id');
            })
            ->orderBy('id');

        if ($brandId !== null && $brandId !== '') {
            $query->where('brand_id', $brandId);
        }

        foreach ($query->cursor() as $order) {
            // #231, the same question `ExpireCarts` asks: a hosted session
            // may have been paid without its webhook ever arriving, and a
            // sweep that cancels without asking cancels a paid order. Asked
            // outside the transaction — a network call must not be made
            // while a row lock is held.
            if (! $this->providerSaysThisOrderMayBeCancelled($order)) {
                continue;
            }

            $handled = DB::transaction(function () use ($order): bool {
                $locked = Order::withoutBrandScope()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->status !== OrderStatus::Pending) {
                    return false;
                }

                // No `CancelOrder` Action covers this transition and `Order`
                // has no `cancelled_at` column — set directly, exactly as
                // `ExpireCarts`'s checkout-grace pass does for the cart case.
                $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

                event(new OrderCancelled(
                    orderId: $locked->id,
                    brandId: $locked->brand_id,
                    settlesType: $locked->settles_type,
                    settlesId: $locked->settles_id,
                    // Nobody acted: this is a deadline passing.
                    cancelledByUserId: null,
                    reasonCode: self::REASON_PAYMENT_WINDOW_EXPIRED,
                    creditNoteId: null,
                    cancelledAt: now()->toIso8601String(),
                ));

                return true;
            });

            if ($handled) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * The provider's word before the deadline's (#231). `false` leaves the
     * order standing for this run: either the money is at the provider and
     * has just been booked through the ordinary success path, or the
     * provider could not say and the next run asks again. Both are logged
     * at warning level, because a lost notification is an operational fact
     * an operator has to be able to see.
     */
    private function providerSaysThisOrderMayBeCancelled(Order $order): bool
    {
        [$state, $payment] = $this->probe->ask($order);

        if ($payment === null) {
            return true;
        }

        if ($state === HostedPaymentState::Paid) {
            Log::warning('Registration-order sweep found a hosted payment the provider calls paid; booking it instead of cancelling (#231).', [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'external_id' => $payment->external_id,
            ]);

            $this->recordSuccess->handle($payment);

            return false;
        }

        if ($state === HostedPaymentState::Undecided) {
            if (! $this->probe->holdExpired($order)) {
                Log::warning('Registration-order sweep left an order standing: the provider could not say whether its hosted payment was paid (#231).', [
                    'order_id' => $order->id,
                    'brand_id' => $order->brand_id,
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'external_id' => $payment->external_id,
                ]);

                return false;
            }

            // The release valve (fix round 1, review finding 4), the same one
            // `ExpireCarts` uses: a provider that never answers must not hold
            // the quota for ever, and money that did move comes back as
            // unapplied money the console can refund (#6).
            Log::warning('Registration-order sweep cancelled an order the provider never answered for; check it for unapplied money (#231).', [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'external_id' => $payment->external_id,
                'order_created_at' => $order->created_at?->toIso8601String(),
            ]);
        }

        return true;
    }
}
