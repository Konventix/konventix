<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions\Concerns;

use Konventix\Commerce\Actions\DeleteProduct;
use Konventix\Commerce\Actions\SaveProduct;
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
     * Whether a `cart_lines` or `order_lines` row still settles against this
     * variant. Neither table carries that reference yet — `cart_lines` and
     * the settlement columns on `order_lines` are Task 9's — so this always
     * answers false today. Task 9 replaces the body with the real check and
     * adds the "refused while referenced" test this docblock promises.
     */
    private function isReferenced(ProductVariant $variant): bool
    {
        return false;
    }
}
