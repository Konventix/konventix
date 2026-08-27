<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions\Concerns;

use Konventix\Commerce\Actions\DeleteProduct;
use Konventix\Commerce\Actions\SaveProduct;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\ProductVariant;

/**
 * Shared by {@see SaveProduct} (removing a
 * variant) and {@see DeleteProduct} (removing a
 * product): a variant that a settled order still points at must not vanish
 * out from under it.
 */
trait GuardsProductVariantReferences
{
    /**
     * The `settles_kind` value an order line carries when it settles a
     * shop product (ADR-0047) — the same string `CartLineKind::Product`
     * carries on a cart line, kept as its own literal here since
     * `order_lines.settles_kind` is a plain column with no enum cast.
     */
    private const string SETTLES_KIND_PRODUCT = 'product';

    /**
     * Whether a `cart_lines` row in an *open* cart, or a settled
     * `order_lines` row, still points at this variant (ADR-0047, Task 9).
     * An expired cart's line does not count — its stock has already been
     * released by the sweep — which is why this filters on the cart's own
     * `status`, not merely on the line existing.
     *
     * Runs on the admin host (SaveProduct/DeleteProduct's own console), so
     * both queries escape the brand scope explicitly rather than relying
     * on ambient brand context that does not exist there
     * (`app-modules-src.md`).
     */
    private function isReferenced(ProductVariant $variant): bool
    {
        $inOpenCart = CartLine::withoutBrandScope()
            ->where('kind', CartLineKind::Product)
            ->where('ref_id', $variant->getKey())
            ->whereIn('cart_id', Cart::withoutBrandScope()->open()->select('id'))
            ->exists();

        if ($inOpenCart) {
            return true;
        }

        return OrderLine::withoutBrandScope()
            ->where('settles_kind', self::SETTLES_KIND_PRODUCT)
            ->where('settles_ref', $variant->getKey())
            ->exists();
    }
}
