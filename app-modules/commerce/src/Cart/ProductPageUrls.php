<?php

declare(strict_types=1);

namespace Konventix\Commerce\Cart;

use Illuminate\Support\Collection;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Pennant\Feature;

/**
 * Where a product line points (bugfix round 2026-09-21, Task 17b): the
 * shop's product page, for the lines of a cart, an order summary or a
 * "my orders" card. A line references a `ProductVariant` by ULID
 * (`cart_lines.ref_id`, `order_lines.settles_ref`), so the page URL is one
 * hop away — the variant's product's slug — and only exists when that page
 * would actually answer: the product is still published and the brand has
 * `commerce:shop` on, the same two conditions `ShopController::show()` and
 * its route middleware enforce. A line whose product was unpublished since,
 * or whose brand switched the shop off, prints as plain text rather than
 * as a link to a 404.
 *
 * `BrandContext` is resolved at call time, never constructor-injected
 * (`.ai/rules/providers.md`, see `CartSummary`).
 */
final class ProductPageUrls
{
    /**
     * @param  iterable<string|null>  $variantIds
     * @return array<string, string> variant id → root-relative product page URL
     */
    public function forVariantIds(iterable $variantIds): array
    {
        $ids = Collection::make($variantIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $brand = app(BrandContext::class)->current();

        if ($brand === null || ! Feature::for($brand)->active('commerce:shop')) {
            return [];
        }

        $productIdByVariantId = ProductVariant::query()->whereIn('id', $ids)->pluck('product_id', 'id');

        $slugByProductId = Product::query()
            ->published()
            ->whereIn('id', $productIdByVariantId->unique()->values())
            ->pluck('slug', 'id');

        return $productIdByVariantId
            ->filter(fn (string $productId): bool => $slugByProductId->has($productId))
            ->map(fn (string $productId): string => route('commerce.shop.show', ['slug' => $slugByProductId[$productId]], absolute: false))
            ->all();
    }
}
