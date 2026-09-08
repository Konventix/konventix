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
 * total is the authoritative figure (it is what the order snapshots), and
 * dividing it is the only way the "each" figure the page prints can be
 * guaranteed to add up to the total printed beside it.
 *
 * `canChangeQuantity` is `false` for a registration line
 * (`ChangeCartLineQuantity` refuses those) so the page never renders
 * quantity controls it cannot use. `maxQuantity` is the product variant's
 * remaining stock *plus* what this line already holds — the ceiling a
 * quantity increase may reach, not the remaining stock alone — and is
 * `null` for a registration line, which has no stock of its own.
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
    ) {}

    public static function ofLine(CartLine $line, string $lineGross, ?int $maxQuantity): self
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
        );
    }
}
