<?php

declare(strict_types=1);

namespace Konventix\Platform\Pricing;

use Konventix\Platform\Data\EndPriceData;

/**
 * What a declared net amount actually costs a visitor (#234, D-COM-20).
 *
 * The same inversion `WaitingRoom\WaitingRoomGate` established and
 * `Legal\PublicLegalTexts` repeated (ADR-0047 point 5): Platform owns the
 * contract, Commerce binds the one implementation, and any module with a
 * public price to print resolves the contract rather than importing
 * Commerce. It is what ADR-0017 needs to reach a page: Registration
 * declares a net amount and a tax *class*, Commerce computes the end price
 * — and until this existed only `/shop` could, because only `/shop` ran
 * through a Commerce controller (#234). Nothing here names a tax rate, a
 * §19 election or a `TaxCalculator`: the boundary is crossed by scalars in
 * and one Data object out, which is what ADR-0004 asks.
 *
 * Batched on purpose. A landing page lists a dozen offers, and one
 * calculator pass per page is the shape `ShopProductData::ofModel()`
 * already uses for a product and its variants — twelve passes would mean
 * twelve operator reads for one answer that cannot differ.
 *
 * The asymmetry to `Pages\RegistrantAudience` is deliberate: that contract
 * fails *closed* (nothing bound means nobody is a registrant, because the
 * question guards a page). This one fails *soft* — nothing bound, an
 * unknown tax class, a brand with no settlement identity yet, all answer
 * `null` for that entry, and the surface then prints the declared net
 * amount with its "excl. VAT" label. A missing end price must cost the
 * page its end price, never the page.
 */
interface EndPriceCalculator
{
    /**
     * The end price of each declared amount, in the order they were given
     * and one entry per input line — `null` where this brand's end price
     * cannot be computed for that line.
     *
     * A line is `unitAmountNet` (a decimal string, never a float) and
     * `taxClass` (the declaring module's own enum *value*, a plain string:
     * two modules never share an enum).
     *
     * @param  list<array{unitAmountNet: string, taxClass: string}>  $lines
     * @return list<EndPriceData|null>
     */
    public function endPricesFor(string $brandId, array $lines): array;
}
