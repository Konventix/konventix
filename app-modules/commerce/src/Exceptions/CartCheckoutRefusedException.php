<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown by `PlaceOrderFromCart`'s own guards — cart not open, cart empty, a
 * registration line whose sales window has closed (#218), chosen account not
 * offered or not hosted-checkout-capable (ADR-0047 point 4) — never by the
 * PSP adapter call the same Action makes once the guards pass.
 *
 * The distinction is what `CartController::checkout()` catches: this
 * class only, translated to a 409 with the message below. An adapter
 * failure (a real Stripe/Mollie/Unzer error) is deliberately left
 * uncaught, the same way `CheckoutController::start()` never wraps
 * `$adapter->startCheckout()` — a raw PSP message must never reach the
 * payer disguised as a domain refusal.
 */
final class CartCheckoutRefusedException extends RuntimeException
{
    public static function cartNotOpen(string $cartId): self
    {
        return new self("Cart [{$cartId}] is not open and has no pending order to resume.");
    }

    public static function cartEmpty(string $cartId): self
    {
        return new self("Cart [{$cartId}] is empty.");
    }

    /**
     * #218 (#216 draft i): the line sat in the cart until its sale ended.
     * The window is read from the line's own snapshot, never from
     * Registration's table (ADR-0004), and it is checked here as well as at
     * line creation because #216 is explicit that a line must not survive
     * the withdrawal of what it sells.
     */
    public static function saleWindowClosed(string $cartLineId): self
    {
        return new self("Cart line [{$cartLineId}] is no longer on sale.");
    }

    public static function accountNotOffered(string $accountId, string $brandId): self
    {
        return new self("Settlement account [{$accountId}] is not offered for brand [{$brandId}].");
    }

    public static function noOnlinePayment(): self
    {
        return new self('This brand takes no online payment.');
    }
}
