<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\ShopProductData;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Slots\SlotRegistry;

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
    public function __construct(
        private readonly MediaLibrary $media,
        private readonly TaxCalculator $taxCalculator,
        private readonly OperatorSettings $operatorSettings,
        private readonly SlotRegistry $slots,
    ) {}

    public function index(BrandContext $brandContext): Response
    {
        $products = Product::query()
            ->published()
            ->with('variants')
            ->orderBy('name')
            ->get();

        $smallBusinessScheme = $this->smallBusinessScheme($brandContext);

        return Inertia::render('shop/Index', [
            'products' => $products
                ->map(fn (Product $product): array => ShopProductData::ofModel(
                    $product,
                    $this->imageUrl($product),
                    $this->taxCalculator,
                    $smallBusinessScheme,
                )->toArray())
                ->all(),
            // What else this brand offers, from modules Commerce knows
            // nothing about (GitLab #240): Registration hangs its own types
            // here, exactly the way Commerce hangs `ShopLandingCard` off
            // Event's landing page, and the slot applies the per-brand
            // activation gate on the way out (ADR-0003 point 3). Commerce
            // never queries a registration table (ADR-0004).
            'slots' => $this->slots->for('shop.index')
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
    }

    /**
     * Addressed by slug, not route-model binding, the same way `content.show`
     * and `policies.show` are: a plain string parameter is what lets
     * `tests/Feature/DryRunTest.php`'s per-route-name `slug` resolver tell
     * this route apart from those.
     */
    public function show(string $slug, BrandContext $brandContext): Response
    {
        $product = Product::query()->published()->where('slug', $slug)->with('variants')->first();

        abort_if($product === null, 404);

        return Inertia::render('shop/Show', [
            'product' => ShopProductData::ofModel(
                $product,
                $this->imageUrl($product),
                $this->taxCalculator,
                $this->smallBusinessScheme($brandContext),
            )->toArray(),
        ]);
    }

    /**
     * The operator's §19 UStG election (ADR-0030), the only thing that can
     * move a public shop price's VAT: there is no billing party on this
     * surface, so reverse charge cannot apply. Read through Platform's
     * `OperatorSettings` doorway rather than any foreign Eloquent model,
     * exactly as `CartController` reads it for the cart's own preview.
     */
    private function smallBusinessScheme(BrandContext $brandContext): bool
    {
        $brand = $brandContext->current();

        abort_if($brand === null, 404);

        return $this->operatorSettings->sellerForBrand($brand->id)->smallBusinessScheme;
    }

    private function imageUrl(Product $product): ?string
    {
        return $product->image_media_id === null ? null : $this->media->urlFor($product->image_media_id);
    }
}
