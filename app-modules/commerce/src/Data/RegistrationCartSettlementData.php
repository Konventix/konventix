<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Models\Cart;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The other half of "what does this registration cost, and where do I pay
 * it" (ADR-0047 point 3, #210): with `commerce:shop` on, an `instant`
 * settlement becomes a cart line instead of an order, so
 * `RegistrationSettlementData`'s order-shaped answer has nothing to report
 * while money is very much owed. This is the cart's answer, rendered by the
 * same `registration.mine.show` slot.
 *
 * Three states, all of them things that actually happen to a line:
 *
 * - `in_cart` — the reservation is alive: pay it in the cart, which is
 *   where the totals, the consent block and the checkout button live.
 *   `expiresAt` is the twenty-minute hold's deadline (ADR-0055 point 4: it
 *   is shown, and never silently extended).
 * - `expired` — the hold lapsed, either because `ExpireCarts` already
 *   swept the cart or because its deadline has passed and the sweep has
 *   not run yet. There is no re-add here; the line's death also cancels the
 *   registration (`CancelPendingRegistrationForCart`), so the honest answer
 *   is what happened, not a button that would re-price a cancelled row.
 * - `checkout_started` — the cart became an order (ADR-0047 point 4). That
 *   order settles the *cart*, not this registration, so it is reachable
 *   through `/my/orders` rather than through this registration's own pay
 *   page.
 */
#[TypeScript]
final class RegistrationCartSettlementData extends Data
{
    public const string STATUS_IN_CART = 'in_cart';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_CHECKOUT_STARTED = 'checkout_started';

    public function __construct(
        public readonly string $status,
        public readonly string $currency,
        public readonly string $totalGross,
        public readonly ?string $expiresAt,
        public readonly ?string $cartUrl,
        public readonly ?string $ordersUrl,
    ) {}

    /**
     * Named `of*` rather than `from*`: laravel-data routes every public
     * static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     */
    public static function ofCart(Cart $cart, string $currency, string $totalGross): self
    {
        $status = self::statusOf($cart);

        return new self(
            status: $status,
            currency: $currency,
            totalGross: $totalGross,
            expiresAt: $status === self::STATUS_IN_CART ? $cart->expires_at->toIso8601String() : null,
            cartUrl: $status === self::STATUS_IN_CART ? route('commerce.cart.show', absolute: false) : null,
            ordersUrl: $status === self::STATUS_CHECKOUT_STARTED ? route('commerce.mine.index', absolute: false) : null,
        );
    }

    /**
     * An `open` cart past its own `expires_at` reads as expired even before
     * `ExpireCarts` has swept it: the reservation is what the deadline says
     * it is, not what the scheduler has got around to. The dev instance in
     * #210 sat in exactly that window.
     */
    private static function statusOf(Cart $cart): string
    {
        return match (true) {
            $cart->status === CartStatus::CheckedOut => self::STATUS_CHECKOUT_STARTED,
            $cart->status === CartStatus::Open && $cart->expires_at->isFuture() => self::STATUS_IN_CART,
            default => self::STATUS_EXPIRED,
        };
    }
}
