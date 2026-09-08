<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Illuminate\Support\Collection;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One brand whose checkout resolves no text for at least one of
 * `CheckoutLegalTexts::SLUGS` (#200).
 *
 * A gap is silent everywhere else: `CheckoutLegalTexts::forBrand()` simply
 * returns fewer entries, the consent control renders nothing, and the
 * checkout goes through without asking anything. The policy console is
 * where an operator can be told, so this is what it is told.
 *
 * `missingSlugs` is the *resolved* answer, not "has no row of its own": a
 * brand that shadows the operator's terms with a policy it never published
 * has a row and no text, which is exactly the case that produced the
 * report in the issue.
 *
 * `gapsFor()` is the one place the list is built (#222): three surfaces
 * warn now — the policy console's index and editor, and the settlement
 * console through the `admin.settlement-accounts` slot — and three copies
 * of the query would be three chances for one of them to disagree with the
 * checkout.
 */
#[TypeScript]
final class CheckoutLegalTextGapData extends Data
{
    /**
     * @param  list<string>  $missingSlugs
     */
    public function __construct(
        public readonly string $brandId,
        public readonly string $brandName,
        public readonly array $missingSlugs,
    ) {}

    /**
     * Every one of these brands the checkout resolves no text for, asked
     * through `CheckoutLegalTexts` itself rather than through a query
     * written a second time: the console must report what the checkout
     * actually sees, including the shadowing rule that makes an unpublished
     * brand policy hide a published operator one.
     *
     * Two queries for the whole list rather than four per brand — the
     * policy console pages, sorts and searches, and recomputes this on each
     * of those.
     *
     * @param  Collection<int, Brand>  $brands
     * @return list<self>
     */
    public static function gapsFor(CheckoutLegalTexts $checkoutLegalTexts, Collection $brands): array
    {
        /** @var list<string> $brandIds */
        $brandIds = array_values($brands->map(fn (Brand $brand): string => (string) $brand->getKey())->all());

        $missing = $checkoutLegalTexts->missingSlugsForBrands($brandIds);

        return array_values($brands
            ->filter(fn (Brand $brand): bool => isset($missing[$brand->getKey()]))
            ->map(fn (Brand $brand): self => new self(
                brandId: (string) $brand->getKey(),
                brandName: $brand->name,
                missingSlugs: $missing[$brand->getKey()],
            ))
            ->all());
    }

    /**
     * The same question for every brand the operator runs — what a page
     * that did not already load a brand list asks. `withoutBrandScope()` is
     * not needed on `brands` itself: `Brand` is Platform's own root and
     * carries no brand scope.
     *
     * @return list<self>
     */
    public static function gapsForAllBrands(CheckoutLegalTexts $checkoutLegalTexts): array
    {
        /** @var Collection<int, Brand> $brands */
        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);

        return self::gapsFor($checkoutLegalTexts, $brands);
    }
}
