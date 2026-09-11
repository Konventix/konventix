<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartCheckedOut;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Exceptions\CartCheckoutRefusedException;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Tax\GermanVat;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * Turns a cart into one order, `settles_type = 'cart'` (ADR-0047 point 4):
 * one checkout settles a mix of products and registrations, each order
 * line carrying `settles_kind`/`settles_ref` from its cart line so a later
 * `OrderLineSettled` (`RecordPaymentSuccess`) knows which of them was paid.
 *
 * Guards, in order — every one of them throws `CartCheckoutRefusedException`,
 * never a raw `RuntimeException`, so the controller can catch exactly these
 * and nothing an adapter call raises: a checked-out cart with a still-pending
 * order reuses that order instead of placing a second one (idempotent — a
 * double-submit or a retried checkout must not fork the cart into two
 * orders); otherwise the cart must be `open` and non-empty, and the chosen
 * account must be live, offered (ADR-0046) and able to host a checkout page,
 * checked *before* the order is created so an unreachable account never
 * leaves a stray pending order behind — a later retry with a good account
 * still finds nothing to reuse, since no order was ever placed. One guard
 * is not of that family: `guardBillingAddress()` (#4) refuses a checkout
 * whose computed gross exceeds `GermanVat::requiresBillingAddress()` and
 * carries no postal address, and it throws `ValidationException` on the
 * address fields themselves rather than `CartCheckoutRefusedException` —
 * a missing address is a form error to fix, not a checkout refused outright.
 *
 * **The sales window is deliberately NOT re-checked here** (#218, fix round
 * 1). A registration line is only ever placed while its sale is open
 * (`PlaceRegistrationLineInCart`), and the registration it settles already
 * exists and already holds its quota. Refusing the checkout of a line that
 * was placed legitimately would leave the payer owing something they cannot
 * pay — the same reasoning that leaves the resumed-order branch below
 * unguarded — and it would fire on the helpful case too: an operator who
 * extends `sales_end` for stragglers would see every cart placed before the
 * edit refuse with a 409 nobody can explain, because the line's window is a
 * snapshot no later edit reaches. The cart's own hold (twenty minutes, plus
 * a sixty-minute checkout grace) is what bounds a line's life; `ExpireCarts`
 * enforces it. The snapshot on the line stays for audit and reconciliation
 * only — nothing renders it.
 *
 * `TaxCalculator::calculate()` runs with no billing party at all
 * (`billingVatId`/`billingCountryCode` both null): a cart has no invoice
 * recipient the way an `invoice`-settled registration does, so this always
 * resolves ADR-0030's domestic-standard treatment (or the operator's own
 * §19 election) — never reverse charge. `startCheckout()` runs outside the
 * transaction that creates the order: the PSP call is a side effect, not a
 * domain write, and a failure there must not roll back a real order that
 * a retried checkout can still pay through the idempotent branch above.
 *
 * **The checkout grace window** (fix round 1): moving the cart to
 * `checked_out` also resets `expires_at` to `Cart::CHECKOUT_GRACE_MINUTES`
 * out — the twenty-minute shopping reservation and the sixty-minute
 * checkout grace are the same column, reused rather than duplicated, since
 * a cart is never both at once. `ExpireCarts`' second pass is what acts on
 * that deadline: an abandoned hosted checkout (closed tab, expired PSP
 * session) must not hold stock and registration lines forever.
 */
final class PlaceOrderFromCart
{
    private const string SETTLES_CART = 'cart';

    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly OperatorSettings $operatorSettings,
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    public function handle(Cart $cart, SettlementAccount $account, string $returnUrl, BillingPartyData $billingParty): CheckoutRedirect
    {
        $reusableOrder = $this->reusableCheckedOutOrder($cart);

        if ($reusableOrder !== null) {
            $cart->forceFill(['expires_at' => now()->addMinutes(Cart::CHECKOUT_GRACE_MINUTES)])->save();

            // Its own lookup — this branch never shares the guard call below.
            $offered = $this->guardAccountOffered($cart->brand_id, $account);

            return $this->sendToProvider($offered, $reusableOrder, $returnUrl);
        }

        if (! $cart->isOpen()) {
            throw CartCheckoutRefusedException::cartNotOpen($cart->id);
        }

        $lines = $cart->lines()->get();

        if ($lines->isEmpty()) {
            throw CartCheckoutRefusedException::cartEmpty($cart->id);
        }

        // Vetted before the order exists (see class docblock).
        $offered = $this->guardAccountOffered($cart->brand_id, $account);

        $order = DB::transaction(fn (): Order => $this->placeOrder($cart, $lines, $billingParty));

        return $this->sendToProvider($offered, $order, $returnUrl);
    }

    /**
     * @param  Collection<int, CartLine>  $lines
     */
    private function placeOrder(Cart $cart, Collection $lines, BillingPartyData $billingParty): Order
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

        $this->guardBillingAddress($calculation->totalGross, $billingParty);

        $order = new Order([
            'settles_type' => self::SETTLES_CART,
            'settles_id' => $cart->id,
            'request_key' => $cart->id,
            'payer_user_id' => $cart->payer_user_id,
            // ADR-0048: a cart order needs a billing party too — always the
            // payer's account identity, plus a postal address whenever the
            // computed gross demands one (`guardBillingAddress()` below, #4;
            // `BillingPartyData::ofAccount`'s own docblock).
            'billing_party' => $billingParty,
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
            // The checkout grace window starts now (Cart::CHECKOUT_GRACE_MINUTES,
            // fix round 1) — reusing `expires_at` rather than a second column,
            // since a cart is never simultaneously shopping and checking out.
            'expires_at' => now()->addMinutes(Cart::CHECKOUT_GRACE_MINUTES),
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

    /**
     * §33 UStDV's small-amount exception stops applying above
     * `GermanVat::SMALL_AMOUNT_INVOICE_THRESHOLD_GROSS` — from there
     * §14 Abs. 4 UStG's full invoice needs the recipient's postal address,
     * and a cart checkout is the only place that address can still be
     * collected before the order (and the PSP session) exists at all (#4).
     * `ValidationException` here, not `CartCheckoutRefusedException`: this
     * refuses one specific field, the same shape
     * `SubmitRegistration::guardBillingParty()` uses for the equivalent
     * check on an invoice-settled registration, so the checkout form shows
     * it the way it shows a missing account or an unaccepted contract.
     *
     * Checked against the computed gross, not a page-load estimate: a
     * cart's contents can still change between the cart page rendering a
     * total and this submit, in either direction — a line added since
     * pushes a small cart over the ceiling, a line removed drops a large
     * one back under it, and this reads the total this checkout actually
     * produced, not one the browser remembered.
     */
    private function guardBillingAddress(string $totalGross, BillingPartyData $billingParty): void
    {
        if (! GermanVat::requiresBillingAddress($totalGross) || $billingParty->hasPostalAddress()) {
            return;
        }

        $missing = array_keys(array_filter([
            'billingAddressLine' => $billingParty->addressLine === null,
            'billingPostalCode' => $billingParty->postalCode === null,
            'billingCity' => $billingParty->city === null,
            'billingCountryCode' => $billingParty->countryCode === null,
        ]));

        throw ValidationException::withMessages(array_fill_keys(
            $missing,
            __(
                'An order over :threshold € needs a billing address before it can be completed (§14 UStG) — the small-amount exception (§33 UStDV) stops here.',
                ['threshold' => GermanVat::SMALL_AMOUNT_INVOICE_THRESHOLD_GROSS],
            ),
        ));
    }

    private function guardAccountOffered(string $brandId, SettlementAccount $account): SettlementAccount
    {
        $offered = SettlementAccount::liveAccountsFor($brandId)->firstWhere('id', $account->id);

        if ($offered === null || ! $offered->isOffered()) {
            throw CartCheckoutRefusedException::accountNotOffered($account->id, $brandId);
        }

        if (! $this->registry->forProvider($offered->provider) instanceof ProvidesHostedCheckout) {
            throw CartCheckoutRefusedException::noOnlinePayment();
        }

        return $offered;
    }

    private function sendToProvider(SettlementAccount $offered, Order $order, string $returnUrl): CheckoutRedirect
    {
        $adapter = $this->registry->forProvider($offered->provider);

        // guardAccountOffered() already resolved and vetted this account —
        // unreachable in practice, but narrows the type for PHPStan (mirrors
        // CheckoutController).
        if (! $adapter instanceof ProvidesHostedCheckout) {
            throw CartCheckoutRefusedException::noOnlinePayment();
        }

        // Deliberately uncaught by this Action: a real adapter failure past
        // this point is not a domain refusal (see class docblock).
        return $adapter->startCheckout($offered, $order, $returnUrl);
    }
}
