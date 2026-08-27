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
     * @param  list<CheckoutAccountData>  $accounts
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $totalNet,
        public readonly string $totalTax,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly ?string $expiresAt,
        public readonly array $accounts,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     *
     * @param  list<CartLineData>  $lines
     * @param  list<CheckoutAccountData>  $accounts
     */
    public static function ofCart(
        ?Cart $cart,
        array $lines,
        string $totalNet,
        string $totalTax,
        string $totalGross,
        string $currency,
        array $accounts,
    ): self {
        return new self(
            lines: $lines,
            totalNet: $totalNet,
            totalTax: $totalTax,
            totalGross: $totalGross,
            currency: $currency,
            expiresAt: $cart?->expires_at->toIso8601String(),
            accounts: $accounts,
        );
    }
}
