<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Illuminate\Support\Collection;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the product editor receives: a product's fields plus its variants,
 * ready for the form to hydrate (ADR-0009, Task 7).
 */
#[TypeScript]
final class ProductData extends Data
{
    /**
     * @param  list<ProductVariantData>  $variants
     */
    public function __construct(
        public readonly string $id,
        public readonly string $brandId,
        public readonly string $brandName,
        public readonly ?string $eventId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $imageMediaId,
        public readonly TaxClass $taxClass,
        public readonly string $unitAmountNet,
        public readonly PriceDisplay $priceDisplay,
        public readonly bool $published,
        public readonly array $variants,
    ) {}

    /**
     * `$variants` is supplied by the caller rather than read off
     * `$product->variants` — that relation call would re-apply `BrandScope`
     * on `ProductVariant` and silently read empty on the admin host
     * (.ai/rules/app-modules-src.md), so the controller escapes it itself and
     * hands the already-scoped collection in.
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    public static function ofModel(Product $product, string $brandName, Collection $variants): self
    {
        return new self(
            id: $product->getKey(),
            brandId: $product->brand_id,
            brandName: $brandName,
            eventId: $product->event_id,
            name: $product->name,
            slug: $product->slug,
            description: $product->description,
            imageMediaId: $product->image_media_id,
            taxClass: $product->tax_class,
            unitAmountNet: $product->unit_amount_net,
            priceDisplay: $product->price_display,
            published: $product->published_at !== null,
            variants: array_values($variants
                ->map(fn (ProductVariant $variant): ProductVariantData => new ProductVariantData(
                    id: $variant->getKey(),
                    label: $variant->label,
                    sku: $variant->sku,
                    unitAmountNet: $variant->unit_amount_net,
                    stockCapacity: $variant->stock_capacity,
                    position: $variant->position,
                    remaining: $variant->remaining(),
                ))
                ->all()),
        );
    }
}
