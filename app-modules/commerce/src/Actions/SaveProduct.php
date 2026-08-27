<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Commerce\Actions\Concerns\GuardsProductVariantReferences;
use Konventix\Commerce\Data\ProductVariantData;
use Konventix\Commerce\Data\SaveProductData;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;

/**
 * Creates or updates a product and syncs its variants (ADR-0047, Task 7).
 *
 * Variants are synced by `id`: a submitted row naming a variant this product
 * owns updates that row; a row with no `id` (or one this product does not
 * own) inserts a new variant; an existing variant missing from the
 * submission is deleted, unless {@see isReferenced()} refuses it. An empty
 * submission creates the single implicit variant every product needs
 * (`Product::isSoldOut()`'s contract), capacity from the Data object's
 * top-level `stockCapacity`.
 */
final class SaveProduct
{
    use GuardsProductVariantReferences;

    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(Brand $brand, ?Product $product, SaveProductData $data): Product
    {
        return DB::transaction(function () use ($brand, $product, $data): Product {
            $this->guardSlug($brand, $data->slug, $product?->getKey());
            $this->guardOwnedMedia($brand, $data->imageMediaId);

            $beforeMediaId = $product?->image_media_id;
            $model = $product ?? new Product;

            // The admin host resolves no brand, so BelongsToBrand has
            // nothing to stamp on a new row — the brand is the operator's
            // explicit choice (mirrors CreateStaticPage).
            if ($product === null) {
                $model->brand_id = $brand->getKey();
            }

            $model->fill([
                'event_id' => $data->eventId,
                'name' => $data->name,
                'slug' => $data->slug,
                'description' => $data->description,
                'image_media_id' => $data->imageMediaId,
                'tax_class' => $data->taxClass,
                'unit_amount_net' => $data->unitAmountNet,
                'price_display' => $data->priceDisplay,
                // Set once: staying published across saves keeps the
                // original moment, never bumping it forward.
                'published_at' => $data->published ? ($model->published_at ?? now()) : null,
            ]);
            $model->save();

            $this->syncVariants($brand, $model, $data);
            $this->syncMediaUsage($model, $beforeMediaId, $data->imageMediaId);

            return $model->refresh();
        });
    }

    private function guardSlug(Brand $brand, string $slug, ?string $ignoreProductId): void
    {
        $taken = Product::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('slug', $slug)
            ->when($ignoreProductId !== null, fn ($query) => $query->whereKeyNot($ignoreProductId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slug' => __('This brand already has a product with that slug.')]);
        }
    }

    private function guardOwnedMedia(Brand $brand, ?string $mediaId): void
    {
        if ($mediaId === null) {
            return;
        }

        // `belongsToBrand()` is `MediaLibrary`'s own doorway for this check
        // (ADR-0035) — Commerce may not query Platform's `MediaItem` model
        // directly across the module boundary (ArchitectureTest, ADR-0004).
        if (! $this->media->belongsToBrand($mediaId, $brand->getKey())) {
            throw ValidationException::withMessages(['imageMediaId' => __('Choose an image from this brand\'s media library.')]);
        }
    }

    private function syncVariants(Brand $brand, Product $product, SaveProductData $data): void
    {
        $existing = ProductVariant::withoutBrandScope()
            ->where('product_id', $product->getKey())
            ->get()
            ->keyBy('id');

        // An empty submission is the implicit-variant shape, not "no
        // variants" — a real product always keeps exactly one row. Reusing
        // the product's current sole variant (rather than always minting a
        // fresh `id: null` one) is what lets the ordinary sync path below
        // apply the capacity-vs-consumed guard to it and keeps its
        // `stock_consumed`; a product that currently has zero or several
        // variants gets a brand new implicit one, same as before.
        $isImplicit = $data->variants === [];
        $incoming = $isImplicit ? [$this->implicitVariant($data, $existing)] : $data->variants;

        $keptIds = [];

        foreach ($incoming as $index => $variant) {
            /** @var ProductVariant|null $row */
            $row = $variant->id !== null ? $existing->get($variant->id) : null;

            if ($row !== null && $variant->stockCapacity < $row->stock_consumed) {
                // The implicit shape has no `variants.{i}` field on the form
                // — key the error to the field the operator actually edited.
                $errorKey = $isImplicit ? 'stockCapacity' : "variants.{$index}.stockCapacity";

                throw ValidationException::withMessages([
                    $errorKey => __('Capacity cannot drop below the stock already consumed.'),
                ]);
            }

            if ($row === null) {
                $row = new ProductVariant;
                $row->brand_id = $brand->getKey();
                $row->product_id = $product->getKey();
            }

            $row->fill([
                'label' => $variant->label,
                'sku' => $variant->sku,
                'unit_amount_net' => $variant->unitAmountNet,
                'stock_capacity' => $variant->stockCapacity,
                'position' => $variant->position,
            ]);
            $row->save();

            $keptIds[] = $row->getKey();
        }

        foreach ($existing as $id => $row) {
            if (in_array($id, $keptIds, true)) {
                continue;
            }

            if ($this->isReferenced($row)) {
                throw ValidationException::withMessages(['variants' => __('A variant referenced by an order cannot be removed.')]);
            }

            $row->delete();
        }
    }

    /**
     * @param  Collection<string, ProductVariant>  $existing
     */
    private function implicitVariant(SaveProductData $data, Collection $existing): ProductVariantData
    {
        if ($data->stockCapacity === null) {
            throw ValidationException::withMessages([
                'stockCapacity' => __('Stock capacity is required when the product has no separate variants.'),
            ]);
        }

        // Exactly one existing variant is the implicit row from a previous
        // save — reuse its id so this save updates it instead of deleting it
        // and minting a fresh one that starts stock_consumed back at 0. Zero
        // or several existing variants get a brand new implicit row.
        $id = $existing->count() === 1 ? $existing->keys()->first() : null;

        return new ProductVariantData(
            id: $id,
            label: null,
            sku: null,
            unitAmountNet: null,
            stockCapacity: $data->stockCapacity,
            position: 0,
        );
    }

    private function syncMediaUsage(Product $product, ?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        if ($before !== null) {
            $this->media->releaseUsage($before, 'commerce', 'product', $product->getKey());
        }

        if ($after !== null) {
            $this->media->recordUsage($after, 'commerce', 'product', $product->getKey());
        }
    }
}
