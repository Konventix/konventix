<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Order;

/**
 * Where an order that still owes money is paid (GitLab #5).
 *
 * One answer, because two mails ask it — the order confirmation and the
 * payment-failed notice — and a confirmation that pointed somewhere else
 * than the retry mail would teach the payer two different places to go.
 *
 * The rule:
 *
 * - a `registration` order pays on its own public checkout page
 *   (`commerce.pay.show`, ADR-0039), which takes a further attempt for as
 *   long as the order is pending and nothing has succeeded;
 * - a `cart` order pays back on the cart itself while the checkout-grace
 *   hold is still alive (ADR-0047 point 4) — `CartController::checkout()`
 *   resumes the same order through `PlaceOrderFromCart`'s idempotent
 *   branch, so this is a retry and never a second order;
 * - once that hold is gone the cart is about to be swept
 *   (`Actions\ExpireCarts`, which cancels the order and releases the
 *   lines), so the link is the payer's order list instead: it still exists
 *   the next morning, when the cart page would only show an empty basket.
 *
 * Null when there is nothing to pay — a settled or cancelled order gets no
 * link at all, rather than one that dead-ends in a 409.
 *
 * Route names and parameters, not URLs: a brand answers on several domains
 * (ADR-0001) and only the caller knows whether it needs an absolute URL for
 * a mail or a root-relative one for a page.
 */
final class PayLink
{
    /**
     * @param  Cart|null  $checkedOutCart  the cart this order was checked out
     *                                     from, where there is one
     * @return array{route: string, parameters: array<string, string>}|null
     */
    public function forOrder(Order $order, ?Cart $checkedOutCart = null): ?array
    {
        // ADR-0057 point 3: a reversed order is owed again and
        // `CheckoutController::start()` takes it, so it keeps its link.
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::PaymentReversed], true)) {
            return null;
        }

        if ($order->settles_type === 'registration') {
            return ['route' => 'commerce.pay.show', 'parameters' => ['registration' => $order->settles_id]];
        }

        $held = $checkedOutCart !== null && $checkedOutCart->expires_at->isFuture();

        return $held
            ? ['route' => 'commerce.cart.show', 'parameters' => []]
            : ['route' => 'commerce.mine.index', 'parameters' => []];
    }
}
