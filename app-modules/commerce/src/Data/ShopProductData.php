<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Tax\TaxCalculator;
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
 * `unitAmountGross` is the PAngV end price #3 asks for: the *server*
 * computes it, through the same `TaxCalculator` the cart preview and the
 * invoice run through, so the three can only ever agree. It is carried
 * beside the net amount rather than replacing it because `priceDisplay`
 * decides which of the two a given brand shows — a B2B shop legitimately
 * shows net, and the page then has to label it.
 *
 * The whole product costs one calculator pass: the product's own price
 * and every variant's effective price go in as lines of one calculation,
 * all under the product's declared tax class (a variant overrides the
 * price, never the class).
 *
 * `smallBusinessScheme` is the resolved `TaxTreatment` reduced to the one
 * fact a price label needs (#244): whether the operator's §19 UStG election
 * applies, in which case neither "incl. VAT" nor "excl. VAT" is true and
 * the page owes the statutory note instead. It travels with the priced
 * product rather than as a page prop beside it, so the landing card, the
 * listing and the product page cannot disagree — and it is the treatment,
 * not `unitAmountGross === unitAmountNet`, because a zero rate is an amount
 * and the election is a status.
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
        public readonly string $unitAmountGross,
        public readonly PriceDisplay $priceDisplay,
        public readonly bool $smallBusinessScheme,
        public readonly bool $soldOut,
        public readonly array $variants,
    ) {}

    /**
     * @param  bool  $operatorSmallBusinessScheme  the operator's §19 UStG
     *                                             election (ADR-0030), which zeroes VAT for every buyer — resolved by
     *                                             the caller through Platform's `OperatorSettings`, never read here.
     */
    public static function ofModel(
        Product $product,
        ?string $imageUrl,
        TaxCalculator $taxCalculator,
        bool $operatorSmallBusinessScheme,
    ): self {
        // `ShopVariantData::ofModel()` reads `effectiveUnitAmountNet()`,
        // which falls back to `$variant->product->unit_amount_net` for a
        // variant with no price override of its own — the common case. The
        // caller only ever eager-loads `variants`, not the reverse
        // `variants.product`, so the relation is set back here rather than
        // left to strict mode's lazy-load guard (`Model::shouldBeStrict()`)
        // to reject it.
        $product->variants->each(fn (ProductVariant $variant): ProductVariant => $variant->setRelation('product', $product));

        // One pass over the product's own price plus every variant's, in
        // that order: line 0 is the product, line n+1 is variant n. No
        // billing party exists on a public shop page — a visitor is a
        // consumer until a checkout says otherwise (ADR-0030) — so reverse
        // charge cannot apply here and only the §19 election can move the
        // rate.
        $variants = array_values($product->variants->all());

        $calculation = $taxCalculator->calculate(
            lines: array_map(fn (string $unitAmountNet): array => [
                'label' => $product->name,
                'quantity' => 1,
                'unit_amount_net' => $unitAmountNet,
                'tax_class' => $product->tax_class->value,
            ], [
                $product->unit_amount_net,
                ...array_map(
                    fn (ProductVariant $variant): string => $variant->effectiveUnitAmountNet(),
                    $variants,
                ),
            ]),
            operatorSmallBusinessScheme: $operatorSmallBusinessScheme,
            billingVatId: null,
            billingCountryCode: null,
        );

        return new self(
            id: $product->getKey(),
            name: $product->name,
            slug: $product->slug,
            description: $product->description,
            imageUrl: $imageUrl,
            unitAmountNet: $product->unit_amount_net,
            unitAmountGross: $calculation->lines[0]->lineGross,
            priceDisplay: $product->price_display,
            smallBusinessScheme: $calculation->treatment === TaxTreatment::SmallBusiness,
            soldOut: $product->isSoldOut(),
            variants: array_map(
                fn (ProductVariant $variant, int $index): ShopVariantData => ShopVariantData::ofModel(
                    $variant,
                    $calculation->lines[$index + 1]->lineGross,
                ),
                $variants,
                array_keys($variants),
            ),
        );
    }
}
