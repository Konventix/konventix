<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartCheckedOut;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Operators\OperatorSettings;
use RuntimeException;

/**
 * Turns a cart into one order, `settles_type = 'cart'` (ADR-0047 point 4):
 * one checkout settles a mix of products and registrations, each order
 * line carrying `settles_kind`/`settles_ref` from its cart line so a later
 * `OrderLineSettled` (`RecordPaymentSuccess`) knows which of them was paid.
 *
 * Guards, in order: a checked-out cart with a still-pending order reuses
 * that order instead of placing a second one (idempotent — a double-submit
 * or a retried checkout must not fork the cart into two orders); otherwise
 * the cart must be `open` and non-empty, and the chosen account must be
 * live, offered (ADR-0046) and able to host a checkout page, checked
 * *before* the order is created so an unreachable account never leaves a
 * stray pending order behind — a later retry with a good account still
 * finds nothing to reuse, since no order was ever placed.
 *
 * `TaxCalculator::calculate()` runs with no billing party at all
 * (`billingVatId`/`billingCountryCode` both null): a cart has no invoice
 * recipient the way an `invoice`-settled registration does, so this always
 * resolves ADR-0030's domestic-standard treatment (or the operator's own
 * §19 election) — never reverse charge. `startCheckout()` runs outside the
 * transaction that creates the order: the PSP call is a side effect, not a
 * domain write, and a failure there must not roll back a real order that
 * a retried checkout can still pay through the idempotent branch above.
 */
final class PlaceOrderFromCart
{
    private const string SETTLES_CART = 'cart';

    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly OperatorSettings $operatorSettings,
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    public function handle(Cart $cart, SettlementAccount $account, string $returnUrl): CheckoutRedirect
    {
        $reusableOrder = $this->reusableCheckedOutOrder($cart);

        if ($reusableOrder !== null) {
            return $this->sendToProvider($cart->brand_id, $account, $reusableOrder, $returnUrl);
        }

        if (! $cart->isOpen()) {
            throw new RuntimeException("Cart [{$cart->id}] is not open and has no pending order to resume.");
        }

        $lines = $cart->lines()->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException("Cart [{$cart->id}] is empty.");
        }

        // Vetted before the order exists (see class docblock).
        $this->guardAccountOffered($cart->brand_id, $account);

        $order = DB::transaction(fn (): Order => $this->placeOrder($cart, $lines));

        return $this->sendToProvider($cart->brand_id, $account, $order, $returnUrl);
    }

    /**
     * @param  Collection<int, CartLine>  $lines
     */
    private function placeOrder(Cart $cart, Collection $lines): Order
    {
        $seller = $this->operatorSettings->sellerForBrand($cart->brand_id);

        $calculation = $this->taxCalculator->calculate(
            lines: array_values($lines->map(fn (CartLine $line): array => [
                'label' => $line->label,
                'quantity' => $line->quantity,
                'unit_amount_net' => $line->unit_amount_net,
                'tax_class' => $line->tax_class->value,
            ])->all()),
            operatorSmallBusinessScheme: $seller->smallBusinessScheme,
            billingVatId: null,
            billingCountryCode: null,
        );

        $order = new Order([
            'settles_type' => self::SETTLES_CART,
            'settles_id' => $cart->id,
            'request_key' => $cart->id,
            'payer_user_id' => $cart->payer_user_id,
            'status' => OrderStatus::Pending,
            'currency' => $this->operatorSettings->currencyForBrand($cart->brand_id),
            'total_net' => $calculation->totalNet,
            'total_tax' => $calculation->totalTax,
            'total_gross' => $calculation->totalGross,
            'tax_treatment' => $calculation->treatment,
            // Inert for a cart order — it is never invoiced — kept at the
            // shop's own gross-inclusive display default.
            'price_display' => 'gross',
        ]);
        // brand_id from the cart, not ambient context (ADR-0010).
        $order->brand_id = $cart->brand_id;
        $order->save();

        foreach ($calculation->lines as $index => $calculatedLine) {
            $cartLine = $lines[$index];

            $orderLine = $order->lines()->make([
                'position' => $index + 1,
                'label' => $calculatedLine->label,
                'settles_kind' => $cartLine->kind->value,
                'settles_ref' => $cartLine->ref_id,
                'quantity' => $calculatedLine->quantity,
                'unit_amount_net' => $calculatedLine->unitAmountNet,
                'tax_class' => $calculatedLine->taxClass,
                'tax_rate' => $calculatedLine->taxRate,
                'line_net' => $calculatedLine->lineNet,
                'line_tax' => $calculatedLine->lineTax,
                'line_gross' => $calculatedLine->lineGross,
            ]);
            $orderLine->brand_id = $cart->brand_id;
            $orderLine->save();
        }

        $cart->forceFill([
            'status' => CartStatus::CheckedOut,
            'checked_out_order_id' => $order->id,
        ])->save();

        event(new CartCheckedOut(
            cartId: $cart->id,
            brandId: $cart->brand_id,
            payerUserId: $cart->payer_user_id,
            orderId: $order->id,
            checkedOutAt: now()->toIso8601String(),
        ));

        event(new OrderPlaced(
            orderId: $order->id,
            brandId: $cart->brand_id,
            settlesType: self::SETTLES_CART,
            settlesId: $cart->id,
            totalNet: $calculation->totalNet,
            totalGross: $calculation->totalGross,
            currency: $order->currency,
            placedAt: now()->toIso8601String(),
        ));

        return $order;
    }

    /**
     * A cart already `checked_out` reuses its order as long as that order
     * is still `pending` — an already-paid or already-refunded one has
     * nothing left to check out into (ADR-0047 point 4, idempotent replay).
     */
    private function reusableCheckedOutOrder(Cart $cart): ?Order
    {
        if ($cart->status !== CartStatus::CheckedOut || $cart->checked_out_order_id === null) {
            return null;
        }

        $order = Order::withoutBrandScope()->whereKey($cart->checked_out_order_id)->first();

        return $order !== null && $order->status === OrderStatus::Pending ? $order : null;
    }

    private function guardAccountOffered(string $brandId, SettlementAccount $account): void
    {
        $offered = SettlementAccount::liveAccountsFor($brandId)->firstWhere('id', $account->id);

        if ($offered === null || ! $offered->isOffered()) {
            throw new RuntimeException("Settlement account [{$account->id}] is not offered for brand [{$brandId}].");
        }

        if (! $this->registry->forProvider($offered->provider) instanceof ProvidesHostedCheckout) {
            throw new RuntimeException('This brand takes no online payment.');
        }
    }

    private function sendToProvider(string $brandId, SettlementAccount $account, Order $order, string $returnUrl): CheckoutRedirect
    {
        $offered = SettlementAccount::liveAccountsFor($brandId)->firstWhere('id', $account->id);

        if ($offered === null || ! $offered->isOffered()) {
            throw new RuntimeException("Settlement account [{$account->id}] is not offered for brand [{$brandId}].");
        }

        $adapter = $this->registry->forProvider($offered->provider);

        // guardAccountOffered()/the line above only ever resolves an
        // account whose adapter implements this — unreachable in practice,
        // but narrows the type for PHPStan (mirrors CheckoutController).
        if (! $adapter instanceof ProvidesHostedCheckout) {
            throw new RuntimeException('This brand takes no online payment.');
        }

        return $adapter->startCheckout($offered, $order, $returnUrl);
    }
}
