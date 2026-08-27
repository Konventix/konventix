<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A shop product as a visitor sees it (ADR-0047, Task 8) — shared between
 * the listing (`/shop`) and the product page (`/shop/{slug}`) rather than
 * split the way the admin console splits `ProductRowData`/`ProductData`:
 * both public screens show the same shape, the listing simply renders less
 * of it.
 *
 * `imageUrl` arrives pre-resolved because Commerce may not import
 * Platform's `MediaItem` (ArchitectureTest) — the caller resolves it
 * through `MediaLibrary::urlFor()` first, the same boundary
 * `ProductAdminController` already respects.
 *
 * `$product->variants` must be eager-loaded before this runs:
 * `Product::isSoldOut()` and the variant list both read the loaded
 * relation, and an unloaded one would N+1 across every product on the
 * listing.
 */
#[TypeScript]
final class ShopProductData extends Data
{
    /**
     * @param  list<ShopVariantData>  $variants
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        public readonly string $unitAmountNet,
        public readonly PriceDisplay $priceDisplay,
        public readonly bool $soldOut,
        public readonly array $variants,
    ) {}

    public static function ofModel(Product $product, ?string $imageUrl): self
    {
        // `ShopVariantData::ofModel()` reads `effectiveUnitAmountNet()`,
        // which falls back to `$variant->product->unit_amount_net` for a
        // variant with no price override of its own — the common case. The
        // caller only ever eager-loads `variants`, not the reverse
        // `variants.product`, so the relation is set back here rather than
        // left to strict mode's lazy-load guard (`Model::shouldBeStrict()`)
        // to reject it.
        $product->variants->each(fn (ProductVariant $variant): ProductVariant => $variant->setRelation('product', $product));

        return new self(
            id: $product->getKey(),
            name: $product->name,
            slug: $product->slug,
            description: $product->description,
            imageUrl: $imageUrl,
            unitAmountNet: $product->unit_amount_net,
            priceDisplay: $product->price_display,
            soldOut: $product->isSoldOut(),
            variants: array_values($product->variants
                ->map(fn (ProductVariant $variant): ShopVariantData => ShopVariantData::ofModel($variant))
                ->all()),
        );
    }
}
