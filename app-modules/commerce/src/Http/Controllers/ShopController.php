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
                    'props' => $this->withGrossPrices($entry['props'], $smallBusinessScheme),
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
     * The `shop.index` slot's one payload rule, and the whole of ADR-0017 in
     * five lines (GitLab #240, review finding 8).
     *
     * A contributing module declares what its offer costs — a net amount and
     * a tax *class*, plain scalars — and Commerce computes the end price. So
     * a contribution's props may carry `offers[]`, each with an optional
     * `price` of `{unitAmountNet, taxClass, display}`, and this fills in
     * `unitAmountGross` on the way out, through the very `TaxCalculator` the
     * product cards, the cart preview and the invoice run through. The
     * contributor learns no tax rate and Commerce reads no foreign table:
     * the boundary is crossed by scalars, which is what ADR-0004 asks and
     * what the settlement event payload already does.
     *
     * PAngV (#3) is why it has to happen at all: `/shop` is a consumer sales
     * page, so a `gross` price display owes the end price rather than a net
     * amount with a label.
     *
     * One calculator pass for every offer in the contribution, in order —
     * the same shape `ShopProductData::ofModel()` uses for a product and its
     * variants. No billing party exists on a public shop page (ADR-0030), so
     * only the §19 election can move the rate.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function withGrossPrices(array $props, bool $smallBusinessScheme): array
    {
        if (! is_array($props['offers'] ?? null)) {
            return $props;
        }

        /** @var list<array<string, mixed>> $offers */
        $offers = array_values($props['offers']);

        /** @var list<int> $pricedIndexes */
        $pricedIndexes = [];
        /** @var list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}> $lines */
        $lines = [];

        foreach ($offers as $index => $offer) {
            $price = $offer['price'] ?? null;

            if (! is_array($price)) {
                continue;
            }

            $unitAmountNet = $price['unitAmountNet'] ?? null;

            // A contribution that declares a price owes a decimal string and
            // a tax class (ADR-0017's own shape). Anything else is skipped
            // rather than fed to bcmath: this payload crosses a module
            // boundary, and a malformed offer must cost that offer its
            // price, not the whole page.
            if (! is_string($unitAmountNet) || ! is_numeric($unitAmountNet) || ! is_string($price['taxClass'] ?? null)) {
                continue;
            }

            $pricedIndexes[] = $index;
            $lines[] = [
                'label' => is_string($offer['name'] ?? null) ? $offer['name'] : '',
                'quantity' => 1,
                'unit_amount_net' => $unitAmountNet,
                'tax_class' => $price['taxClass'],
            ];
        }

        if ($lines === []) {
            return $props;
        }

        $calculation = $this->taxCalculator->calculate(
            lines: $lines,
            operatorSmallBusinessScheme: $smallBusinessScheme,
            billingVatId: null,
            billingCountryCode: null,
        );

        foreach ($pricedIndexes as $position => $index) {
            $offers[$index]['price']['unitAmountGross'] = $calculation->lines[$position]->lineGross;
        }

        $props['offers'] = $offers;

        return $props;
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
