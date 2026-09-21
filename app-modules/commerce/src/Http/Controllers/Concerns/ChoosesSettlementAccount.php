<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Settlement\CheckoutConsentSlots;

/**
 * The two-step checkout's shared machinery (ADR-0066 §6.3, D-CPL-07):
 * remembering which settlement account the payer chose in step one, and
 * building the context step two resolves its legal texts from.
 *
 * **Why two steps at all.** The texts that apply may depend on the provider
 * — "our Stripe addendum, but only at the big event" is expressible — so the
 * provider is chosen first and the texts are shown after. A payer may not be
 * held to have agreed to a list they never saw, which is also why going back
 * to step one clears the choice: a new choice re-resolves the list, and an
 * acceptance made against the old one is gone with the page that carried it.
 *
 * **Where the choice lives.** In the session, keyed per checkout target,
 * rather than in a column on `orders`. The cart path has no order yet when
 * step one happens (ADR-0047 point 3 — the order is placed at the end of
 * step two), so a column could not serve both paths, and two mechanisms for
 * one decision is how the cart checkout and the pay page drift apart. The
 * choice is not a declaration of anything and survives nothing: it is
 * forgotten when the payer lands on step one again, and when the checkout
 * starts.
 *
 * The acceptance itself is never stored here. It is a field of the POST that
 * starts the checkout, validated against the remembered provider, so there
 * is no state in which the server believes somebody accepted something.
 */
trait ChoosesSettlementAccount
{
    private const string ACCOUNT_CHOICE_SESSION = 'commerce.checkout.account';

    /**
     * The session key for one checkout target — an order, or a cart.
     */
    private function accountChoiceKey(string $scope, string $id): string
    {
        return self::ACCOUNT_CHOICE_SESSION.'.'.$scope.'.'.$id;
    }

    private function rememberedAccountId(Request $request, string $scope, string $id): ?string
    {
        $value = $request->session()->get($this->accountChoiceKey($scope, $id));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function rememberAccountId(Request $request, string $scope, string $id, string $accountId): void
    {
        $request->session()->put($this->accountChoiceKey($scope, $id), $accountId);
    }

    /**
     * Landing on step one, or finishing, clears the choice — and with it the
     * acceptance, which only ever existed as a tick on the page that choice
     * produced (D-CPL-07).
     */
    private function forgetAccountChoice(Request $request, string $scope, string $id): void
    {
        $request->session()->forget($this->accountChoiceKey($scope, $id));
    }

    /**
     * What the consent slot resolves against for this order (D-CPL-06): the
     * union of its lines' opaque bags, plus the two facts Commerce owns.
     *
     * Nothing here reads a key's meaning. The bags were written by a
     * settlement request and are copied on.
     *
     * @return array<string, mixed>
     */
    private function bindingContextForOrder(Order $order, ?string $settlementProvider): array
    {
        return [
            'bindingContext' => CheckoutConsentSlots::unionOfBags(
                array_values($order->lines->map(
                    fn (OrderLine $line): ?array => $line->binding_context,
                )->all()),
            ),
            'settlementProvider' => $settlementProvider,
            'orderId' => (string) $order->getKey(),
        ];
    }

    /**
     * The same for a cart, which has no order id yet.
     *
     * @param  iterable<int, CartLine>  $lines
     * @return array<string, mixed>
     */
    private function bindingContextForCart(Cart $cart, iterable $lines, ?string $settlementProvider): array
    {
        $bags = [];

        foreach ($lines as $line) {
            $bags[] = $line->binding_context;
        }

        return [
            'bindingContext' => CheckoutConsentSlots::unionOfBags($bags),
            'settlementProvider' => $settlementProvider,
            'orderId' => null,
        ];
    }
}
