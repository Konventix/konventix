<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Commerce\Actions\Concerns\GuardsProductVariantReferences;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Deletes a product, refusing while any of its variants is still referenced
 * by an order (ADR-0047, Task 7), and releases the image usage it held
 * (ADR-0035). Variant rows themselves cascade off the FK — nothing else
 * references one directly.
 */
final class DeleteProduct
{
    use GuardsProductVariantReferences;

    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $variants = ProductVariant::withoutBrandScope()
                ->where('product_id', $product->getKey())
                ->get();

            foreach ($variants as $variant) {
                if ($this->isReferenced($variant)) {
                    throw ValidationException::withMessages([
                        'product' => __('This product has a variant still referenced by an order and cannot be deleted.'),
                    ]);
                }
            }

            if ($product->image_media_id !== null) {
                $this->media->releaseUsage($product->image_media_id, 'commerce', 'product', $product->getKey());
            }

            $product->delete();
        });
    }
}
