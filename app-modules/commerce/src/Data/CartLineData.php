<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Models\CartLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of the cart page (ADR-0047, Task 11). `lineGross` is a
 * *preview* — the tax-inclusive amount `TaxCalculator::calculate()`
 * computes against the whole cart at render time, not a value stored on
 * the line itself (`CartLine` only snapshots `unit_amount_net`); checkout
 * recomputes it for real onto the order.
 *
 * `unitAmountGross` is the per-unit end price PAngV asks for (#3),
 * derived from `lineGross` rather than calculated a second time: the line
 * total is the authoritative figure — it is what the order snapshots — so
 * the "each" figure is read off it rather than computed beside it, where a
 * second rounding could disagree with the total in the same row.
 *
 * It is the closest per-unit figure to that total, not a factor of it: at
 * 10.01 net and quantity 3 the line is 35.74 gross and this reads 11.91,
 * which multiplies back to 35.73. One cent, on a figure nobody is asked to
 * multiply, and the authoritative number is the one printed beside it.
 *
 * `canChangeQuantity` is `false` for a registration line
 * (`ChangeCartLineQuantity` refuses those) so the page never renders
 * quantity controls it cannot use. `maxQuantity` is the product variant's
 * remaining stock *plus* what this line already holds — the ceiling a
 * quantity increase may reach, not the remaining stock alone — and is
 * `null` for a registration line, which has no stock of its own.
 *
 * `url` is where the label links (bugfix round 2026-09-21, Task 17b): the
 * registration's own page for a registration line, the shop product page
 * for a product line whose product is still published
 * (`Cart\ProductPageUrls`), null otherwise — resolved by the controller,
 * which already holds the variants, and rendered as plain text when null.
 */
#[TypeScript]
final class CartLineData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly int $quantity,
        public readonly string $unitAmountNet,
        public readonly string $unitAmountGross,
        public readonly string $lineGross,
        public readonly bool $canChangeQuantity,
        public readonly ?int $maxQuantity,
        public readonly ?string $url,
    ) {}

    public static function ofLine(CartLine $line, string $lineGross, ?int $maxQuantity, ?string $url = null): self
    {
        return new self(
            id: $line->getKey(),
            kind: $line->kind->value,
            label: $line->label,
            quantity: $line->quantity,
            unitAmountNet: $line->unit_amount_net,
            unitAmountGross: bcround(bcdiv($lineGross, (string) max(1, $line->quantity), 4), 2),
            lineGross: $lineGross,
            canChangeQuantity: $line->kind === CartLineKind::Product,
            maxQuantity: $maxQuantity,
            url: $url,
        );
    }
}
