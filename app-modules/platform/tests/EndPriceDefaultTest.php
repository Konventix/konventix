<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
use Konventix\Platform\Pricing\EndPriceCalculator;
use Konventix\Platform\Pricing\NoEndPrices;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Models\RegistrationType;

/**
 * Platform's `EndPriceCalculator` (#234, D-COM-20): Platform owns the
 * contract and binds only the null default — Commerce binds the real
 * calculator in its own provider, and in the assembled application that
 * `bind()` wins over this `singletonIf()` whichever order the two providers
 * register in.
 *
 * What is proven here is the other half: the moment nothing answers, every
 * public surface must degrade to the declared net amount with its
 * "excl. VAT" label, which is what all of them printed before #234. This is
 * the deliberate asymmetry to `Pages\RegistrantAudience`, which fails
 * *closed* because its question guards a page — a missing end price costs
 * the page its end price, never the page. `WaitingRoomAliasTest` is the
 * same shape for the same reason.
 *
 * Helper name prefixed `endPriceDefault*` — Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function endPriceDefaultLandingOffers(AssertableInertia $page): array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'registration/EventOffers');

    return $slot === null ? [] : $slot['props']['offers'];
}

it('answers one null per line and never throws', function (): void {
    $calculator = new NoEndPrices;

    expect($calculator->endPricesFor('any-brand', [
        ['unitAmountNet' => '19.00', 'taxClass' => 'standard'],
        ['unitAmountNet' => '10.00', 'taxClass' => 'reduced'],
    ]))->toBe([null, null])
        ->and($calculator->endPricesFor('any-brand', []))->toBe([]);
});

it('still renders a priced landing page when nothing is bound', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Weekend Ticket',
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);

    app()->offsetUnset(EndPriceCalculator::class);

    expect(app()->bound(EndPriceCalculator::class))->toBeFalse();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offer = endPriceDefaultLandingOffers($page)[0];

            // The declaration is untouched — the page prints the net
            // amount and says "excl. VAT" — and no end price is claimed.
            expect($offer['price']['unitAmountNet'])->toBe('19.00')
                ->and($offer['endPrice'])->toBeNull();
        });
});

it('does the same with the null default bound', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);

    app()->offsetUnset(EndPriceCalculator::class);
    app()->singleton(EndPriceCalculator::class, NoEndPrices::class);

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(endPriceDefaultLandingOffers($page)[0]['endPrice'])->toBeNull());
});
