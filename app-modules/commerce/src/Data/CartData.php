<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\Cart;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The cart page's whole shape (ADR-0047, Task 11): lines, a tax preview of
 * the totals checkout will recompute for real, and one button per offered
 * settlement account (ADR-0046) — the same `CheckoutAccountData` shape
 * `pay/Show.vue` already renders. `expiresAt` is null when the payer has no
 * open cart at all, which the page reads as the empty state.
 */
#[TypeScript]
final class CartData extends Data
{
    /**
     * @param  list<CartLineData>  $lines
     * @param  list<RemovedCartLineData>  $removedLines
     * @param  list<CheckoutAccountData>  $accounts
     */
    public function __construct(
        public readonly array $lines,
        /**
         * #219's undo window, as the page sees it: the lines the buyer has
         * removed and may still put back. Empty almost always — it is the
         * banner's own `v-if` — and never part of `lines` or the totals,
         * which is the whole point of the soft removal.
         */
        public readonly array $removedLines,
        public readonly string $totalNet,
        public readonly string $totalTax,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly ?string $expiresAt,
        public readonly array $accounts,
        /**
         * §33 UStDV vs §14 Abs. 4 UStG (#4,
         * `GermanVat::requiresBillingAddress()`): whether checkout will
         * refuse to complete without a billing address. The page reads
         * this to ask for the address up front rather than only after a
         * 422 — the authoritative check still lives server-side in
         * `PlaceOrderFromCart::guardBillingAddress()`, since the cart can
         * still change between this render and the submit.
         */
        public readonly bool $billingAddressRequired,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     *
     * @param  list<CartLineData>  $lines
     * @param  list<RemovedCartLineData>  $removedLines
     * @param  list<CheckoutAccountData>  $accounts
     */
    public static function ofCart(
        ?Cart $cart,
        array $lines,
        array $removedLines,
        string $totalNet,
        string $totalTax,
        string $totalGross,
        string $currency,
        array $accounts,
        bool $billingAddressRequired,
    ): self {
        return new self(
            lines: $lines,
            removedLines: $removedLines,
            totalNet: $totalNet,
            totalTax: $totalTax,
            totalGross: $totalGross,
            currency: $currency,
            expiresAt: $cart?->expires_at->toIso8601String(),
            accounts: $accounts,
            billingAddressRequired: $billingAddressRequired,
        );
    }
}
