<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Pricing\TaxCalculatorEndPrices;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Pricing\EndPriceCalculator;

/**
 * Commerce binds Platform's `EndPriceCalculator` (#234, D-COM-20) — the
 * inversion `WaitingRoomGate` set and `PublicLegalTexts` repeated: Platform
 * owns the interface and any module with a public price to print asks it,
 * Commerce answers with the very `TaxCalculator` the cart and the invoice
 * run through. A landing page and a static-page block can have a PAngV end
 * price on them now without importing `Commerce\Tax\*`, which ADR-0004
 * forbids and `ArchitectureTest` enforces.
 *
 * The arithmetic is `GrossPricesTest`'s, on purpose: 19.00 at the standard
 * rate is 22.61 and 10.00 at the reduced one is 10.70 wherever it is asked.
 *
 * Helper names prefixed `endPrice*` — Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function endPriceBrandId(bool $smallBusinessScheme = false): string
{
    $brand = test()->withBrand();

    $factory = Operator::factory();

    if ($smallBusinessScheme) {
        $factory = $factory->smallBusinessScheme();
    }

    $brand->operator()->associate($factory->create())->save();

    return $brand->getKey();
}

it('binds the EndPriceCalculator contract to its own implementation', function (): void {
    expect(app(EndPriceCalculator::class))->toBeInstanceOf(TaxCalculatorEndPrices::class);
});

it('computes the end price of a batch in order, with the rate and the brand currency', function (): void {
    $brandId = endPriceBrandId();

    $endPrices = app(EndPriceCalculator::class)->endPricesFor($brandId, [
        ['unitAmountNet' => '19.00', 'taxClass' => 'standard'],
        ['unitAmountNet' => '10.00', 'taxClass' => 'reduced'],
    ]);

    expect($endPrices)->toHaveCount(2)
        ->and($endPrices[0]->unitAmountNet)->toBe('19.00')
        ->and($endPrices[0]->unitAmountGross)->toBe('22.61')
        ->and($endPrices[0]->taxRate)->toBe('19.00')
        ->and($endPrices[0]->currency)->toBe('EUR')
        ->and($endPrices[0]->smallBusinessScheme)->toBeFalse()
        ->and($endPrices[1]->unitAmountGross)->toBe('10.70')
        ->and($endPrices[1]->taxRate)->toBe('7.00');
});

/**
 * ADR-0030: the operator's §19 election zeroes VAT for every buyer, and the
 * surface has to say so — "incl. VAT" would claim a tax the invoice does
 * not carry (§19 (1) sentence 4 UStG wants the absence stated).
 */
it('carries no tax at all under the operator\'s small-business election', function (): void {
    $brandId = endPriceBrandId(smallBusinessScheme: true);

    $endPrice = app(EndPriceCalculator::class)->endPricesFor($brandId, [
        ['unitAmountNet' => '19.00', 'taxClass' => 'standard'],
    ])[0];

    expect($endPrice->unitAmountGross)->toBe('19.00')
        ->and($endPrice->taxRate)->toBe('0.00')
        ->and($endPrice->smallBusinessScheme)->toBeTrue();
});

/**
 * The lines crossed a module boundary as plain scalars (ADR-0004), so an
 * unknown tax class is a real possibility — two modules keep their own
 * enums and they may drift. `TaxClass::from()` inside the calculator would
 * be an uncaught `ValueError` that 500s whichever public page asked, so the
 * guard answers `null` for that entry and prices everything else.
 */
it('answers null for a line it cannot price, keeping every other line in place', function (): void {
    $brandId = endPriceBrandId();

    $endPrices = app(EndPriceCalculator::class)->endPricesFor($brandId, [
        ['unitAmountNet' => '19.00', 'taxClass' => 'luxury'],
        ['unitAmountNet' => '19.00', 'taxClass' => 'standard'],
        ['unitAmountNet' => 'free, actually', 'taxClass' => 'standard'],
    ]);

    expect($endPrices)->toHaveCount(3)
        ->and($endPrices[0])->toBeNull()
        ->and($endPrices[1]->unitAmountGross)->toBe('22.61')
        ->and($endPrices[2])->toBeNull();
});

it('answers an empty list for an empty batch', function (): void {
    expect(app(EndPriceCalculator::class)->endPricesFor(endPriceBrandId(), []))->toBe([]);
});

/**
 * Fail soft, the asymmetry to `Pages\RegistrantAudience` the contract's own
 * docblock names: a brand with no settlement identity — here, no brand at
 * all behind the id — costs the page its end price, never the page.
 */
it('answers null rather than throwing for a brand it cannot price', function (): void {
    endPriceBrandId();

    $endPrices = app(EndPriceCalculator::class)->endPricesFor((string) Str::ulid(), [
        ['unitAmountNet' => '19.00', 'taxClass' => 'standard'],
    ]);

    expect($endPrices)->toBe([null]);
});
