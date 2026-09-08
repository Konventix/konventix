<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\PaymentReversal;

/**
 * Where an order that still owes money is paid (GitLab #5), and by when a
 * reversed one has to be (GitLab #197).
 *
 * One answer, because three callers ask it — the order confirmation, the
 * payment-failed notice and the payer's own order list — and a confirmation
 * that pointed somewhere else than the retry mail would teach the payer two
 * different places to go.
 *
 * The rule, and it is short because the destination is addressed by *order*
 * since review round 1:
 *
 * - a `registration` order pays on its own public checkout page
 *   (`commerce.pay.show`, ADR-0039);
 * - every other order — a cart order (ADR-0047) — pays on
 *   `commerce.pay.order.show`, which is addressed by the order id itself.
 *   A cart-addressed link could not be trusted: `OpenCart::for()` opens a
 *   second cart as soon as the payer adds one more product, and "the
 *   newest cart" is then not the one the mail was about.
 * - null when there is nothing to pay — a settled or cancelled order gets
 *   no link at all, rather than one that dead-ends.
 *
 * Whether that page can *accept* a payment right now is
 * `CheckoutController::refusalReason()`'s question, not this one: a link
 * that leads to a page explaining "the reservation has run out" is a better
 * answer to a payer holding a mail than no link at all.
 *
 * Route names and parameters, not URLs: a brand answers on several domains
 * (ADR-0001) and only the caller knows whether it needs an absolute URL for
 * a mail or a root-relative one for a page.
 *
 * `repayDueOnFor()` sits here rather than in a class of its own because
 * every surface that offers the link to a reversed order also has to name
 * its deadline (ADR-0057 point 4) — the two pay pages and the registration
 * settlement slot — and a link without the date is the half of the answer
 * that does not tell the payer when the seat goes away.
 */
final class PayLink
{
    /**
     * @return array{route: string, parameters: array<string, string>}|null
     */
    public function forOrder(Order $order): ?array
    {
        // ADR-0057 point 3: a reversed order is owed again and
        // `CheckoutController::start()` takes it, so it keeps its link.
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::PaymentReversed], true)) {
            return null;
        }

        return $order->settles_type === 'registration'
            ? ['route' => 'commerce.pay.show', 'parameters' => ['registration' => $order->settles_id]]
            : ['route' => 'commerce.pay.order.show', 'parameters' => ['order' => $order->getKey()]];
    }

    /**
     * The day a reversed order's money is owed back by (ADR-0057 point 4):
     * the newest final reversal's `repay_due_on`, the same rule the
     * `commerce:cancel-unpaid-reversals` sweep acts on. An open dispute
     * carries none — the provider still holds the money and nothing is
     * owed yet — and so does every order in any other status.
     *
     * Brand-scoped, like the pages that ask: all three callers render on
     * the brand host (ADR-0010).
     */
    public function repayDueOnFor(Order $order): ?string
    {
        if ($order->status !== OrderStatus::PaymentReversed) {
            return null;
        }

        return PaymentReversal::query()
            ->where('order_id', $order->getKey())
            ->where('status', ReversalStatus::Final)
            ->whereNotNull('repay_due_on')
            ->orderByDesc('occurred_at')
            ->first()?->repay_due_on?->toDateString();
    }
}
