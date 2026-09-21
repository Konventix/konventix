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
            // ADR-0030 / branch review finding 12: under the §19 election
            // the calculator puts no VAT on any line, so a card that said
            // "incl. VAT" would claim a tax the invoice does not carry.
            'smallBusinessScheme' => $smallBusinessScheme,
            // The brand's own (#234): a public page prints no hardcoded
            // currency symbol, here or in a contributed offer's end price.
            'currency' => $this->operatorSettings->currencyForBrand($this->brandId($brandContext)),
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
            //
            // The props pass through untouched since #234: this controller
            // used to fill `unitAmountGross` into every contributed offer on
            // its way out, which is why `/shop` was the one public surface
            // with an end price on it. A contributor now asks Platform's
            // `Pricing\\EndPriceCalculator` for its own — the same Commerce
            // arithmetic, reachable from a landing page and a static page
            // too — and this slot has no payload rule left to enforce.
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

        $smallBusinessScheme = $this->smallBusinessScheme($brandContext);

        return Inertia::render('shop/Show', [
            'smallBusinessScheme' => $smallBusinessScheme,
            'currency' => $this->operatorSettings->currencyForBrand($this->brandId($brandContext)),
            'product' => ShopProductData::ofModel(
                $product,
                $this->imageUrl($product),
                $this->taxCalculator,
                $smallBusinessScheme,
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
        return $this->operatorSettings->sellerForBrand($this->brandId($brandContext))->smallBusinessScheme;
    }

    /**
     * The brand this shop belongs to. Never null in practice — the route
     * resolves on a brand host — but `BrandContext` answers null on the
     * admin host (ADR-0029), and a 404 is the honest answer there rather
     * than a type error two frames deeper.
     */
    private function brandId(BrandContext $brandContext): string
    {
        $brand = $brandContext->current();

        abort_if($brand === null, 404);

        return $brand->id;
    }

    private function imageUrl(Product $product): ?string
    {
        return $product->image_media_id === null ? null : $this->media->urlFor($product->image_media_id);
    }
}
