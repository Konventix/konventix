<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\ProductVariant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One purchasable line of a shop product, as a visitor sees it (ADR-0047,
 * Task 8) — the price the variant actually sells at
 * ({@see ProductVariant::effectiveUnitAmountNet()}, which falls back to the
 * product's own price when the variant carries no override) and what is
 * left of it. The add-to-cart form (Task 11) reads `remaining` to know
 * which options in its variant `<select>` to disable.
 */
#[TypeScript]
final class ShopVariantData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $label,
        public readonly string $unitAmountNet,
        public readonly int $remaining,
    ) {}

    public static function ofModel(ProductVariant $variant): self
    {
        return new self(
            id: $variant->getKey(),
            label: $variant->label,
            unitAmountNet: $variant->effectiveUnitAmountNet(),
            remaining: $variant->remaining(),
        );
    }
}
