<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\ShopProductData;
use Konventix\Commerce\Models\Product;
use Konventix\Platform\Media\MediaLibrary;

/**
 * The brand's public shop (ADR-0047, Task 8), resolved on the brand's own
 * domain. Route middleware already requires the `commerce:shop` feature
 * (`ensure-feature:commerce:shop`) — a brand that never switched it on
 * 404s before either action runs (`EnsureFeatureIsActive`).
 *
 * Brand scoping is `BelongsToBrand`'s global scope: this is a brand host,
 * so unlike the operator console nothing here escapes it (ADR-0010).
 */
final class ShopController
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function index(): Response
    {
        $products = Product::query()
            ->published()
            ->with('variants')
            ->orderBy('name')
            ->get();

        return Inertia::render('shop/Index', [
            'products' => $products
                ->map(fn (Product $product): array => ShopProductData::ofModel($product, $this->imageUrl($product))->toArray())
                ->all(),
        ]);
    }

    /**
     * Addressed by slug, not route-model binding, the same way `content.show`
     * and `policies.show` are: a plain string parameter is what lets
     * `tests/Feature/DryRunTest.php`'s per-route-name `slug` resolver tell
     * this route apart from those.
     */
    public function show(string $slug): Response
    {
        $product = Product::query()->published()->where('slug', $slug)->with('variants')->first();

        abort_if($product === null, 404);

        return Inertia::render('shop/Show', [
            'product' => ShopProductData::ofModel($product, $this->imageUrl($product))->toArray(),
        ]);
    }

    private function imageUrl(Product $product): ?string
    {
        return $product->image_media_id === null ? null : $this->media->urlFor($product->image_media_id);
    }
}
