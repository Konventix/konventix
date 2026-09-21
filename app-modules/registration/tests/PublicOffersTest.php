<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Actions\UnpublishEvent;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Models\Event;
use Konventix\Platform\Data\EndPriceData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Pricing\EndPriceCalculator;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Listeners\ProjectEventSummary;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\RegistrationType;

/**
 * The event landing page's call to action. Until this existed the public
 * registration form was reachable only by typing its URL: nothing anywhere
 * in the UI linked to it.
 *
 * Registration contributes it through Event's `event.landing` slot
 * (ADR-0003 enforcement point 3), so what is under test here is as much the
 * boundary as the markup — Event must render offers it never queried.
 */
function landingOffers(AssertableInertia $page): array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'registration/EventOffers');

    return $slot === null ? [] : $slot['props']['offers'];
}

it('offers a public registration type on the event landing page', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    $type = RegistrationType::factory()->forEvent($event->getKey())->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($event, $type): void {
            $offers = landingOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['name'])->toBe($type->name)
                ->and($offers[0]['registrationTypeId'])->toBe($type->getKey())
                ->and($offers[0]['url'])->toBe('/events/'.$event->getKey().'/register/'.$type->getKey());
        });
});

it('never advertises a type that needs a token', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    RegistrationType::factory()->forEvent($event->getKey())->inviteOnly()->create();
    RegistrationType::factory()->forEvent($event->getKey())
        ->visibility(RegistrationVisibility::Link)
        ->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(landingOffers($page))->toBe([]));
});

it('offers only this event\'s types', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    $other = Event::factory()->published()->create();

    $mine = RegistrationType::factory()->forEvent($event->getKey())->create();
    RegistrationType::factory()->forEvent($other->getKey())->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page) use ($mine): void {
            $offers = landingOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['name'])->toBe($mine->name);
        });
});

it('carries the declared price and the approval warning a visitor decides on', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    RegistrationType::factory()->forEvent($event->getKey())
        ->priced('249.00')
        ->requiringApproval()
        ->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $offer = landingOffers($page)[0];

            expect($offer['price']['unitAmountNet'])->toBe('249.00')
                // The declaration travels with what it actually costs
                // (#234): one is Registration's intention, the other
                // Commerce's answer (ADR-0017).
                ->and($offer['endPrice']['unitAmountNet'])->toBe('249.00')
                ->and($offer['endPrice']['unitAmountGross'])->toBe('296.31')
                ->and($offer['requiresApproval'])->toBeTrue();
        });
});

it('reports no price for a free type rather than a zero one', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    RegistrationType::factory()->forEvent($event->getKey())->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $offer = landingOffers($page)[0];

            // And no end price either: a free type has no price at all,
            // never a zero one (ADR-0017), and the card says "Free".
            expect($offer['price'])->toBeNull()
                ->and($offer['endPrice'])->toBeNull();
        });
});

/**
 * #234 (D-COM-20): the defect this whole task exists for. A Commerce card
 * on the event landing page printed the PAngV end price with "incl. VAT"
 * while Registration's card three inches beside it printed the *net*
 * amount with no note at all — for a `display: gross` type the visitor
 * read an amount lower than the one they would pay.
 *
 * The end price arrives computed now, through Platform's
 * `Pricing\EndPriceCalculator`, which Commerce binds to the very
 * `TaxCalculator` the cart and the invoice run through: 19.00 at the
 * standard rate is 22.61 and 10.00 at the reduced one is 10.70, the same
 * arithmetic `GrossPricesTest` pins for products and the shop slot pins
 * below. ADR-0006 is untouched — a `net` type still shows its net amount,
 * labelled, because that is the honest one for a B2B audience.
 */
it('carries the Commerce-computed end price of every priced offer on the landing page', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Weekend Ticket',
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Exhibitor Pass',
        'price_declaration' => new PriceDeclarationData('10.00', TaxClass::Reduced, PriceDisplay::Net),
    ]);

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offers = collect(landingOffers($page))->keyBy('name');

            expect($offers['Weekend Ticket']['price']['display'])->toBe('gross')
                ->and($offers['Weekend Ticket']['endPrice'])->toMatchArray([
                    'unitAmountNet' => '19.00',
                    'unitAmountGross' => '22.61',
                    'taxRate' => '19.00',
                    'currency' => 'EUR',
                    // What the note may claim: this amount does contain VAT.
                    'includesTax' => true,
                ])
                ->and($offers['Exhibitor Pass']['price']['display'])->toBe('net')
                ->and($offers['Exhibitor Pass']['endPrice'])->toMatchArray([
                    'unitAmountNet' => '10.00',
                    'unitAmountGross' => '10.70',
                    'taxRate' => '7.00',
                ]);
        });
});

/**
 * ADR-0030: under the operator's §19 UStG election the calculator puts no
 * VAT on any line, so the end price equals the net amount and the card must
 * say "no VAT (§ 19 UStG)" rather than "incl. VAT" — §19 (1) sentence 4
 * UStG wants the absence stated, and `taxNote()` owns the wording.
 */
it('shows an end price with no VAT in it for an operator on the small-business scheme', function (): void {
    $brand = $this->withBrand();
    $brand->operator()->associate(Operator::factory()->smallBusinessScheme()->create())->save();

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(landingOffers($page)[0]['endPrice'])->toMatchArray([
                'unitAmountGross' => '19.00',
                'taxRate' => '0.00',
                'includesTax' => false,
            ]);
        });
});

/**
 * Fail soft (the contract's own rule, and the asymmetry to
 * `Pages\RegistrantAudience`): an offer Commerce cannot price — an unknown
 * tax class crossing the scalar boundary, a brand with no settlement
 * identity — loses its end price and nothing else. The card then prints the
 * declared net amount with "excl. VAT", which is what this page did before
 * #234. The guard itself lives at the boundary and is pinned by
 * `commerce/tests/EndPriceCalculatorBindingTest`; what matters here is that
 * a `null` entry reaches the page instead of a 500.
 */
it('leaves an offer the calculator cannot price with its declaration and no end price', function (): void {
    $this->withBrand();

    app()->bind(EndPriceCalculator::class, fn (): EndPriceCalculator => new class implements EndPriceCalculator
    {
        /**
         * @param  list<array{unitAmountNet: string, taxClass: string}>  $lines
         * @return list<EndPriceData|null>
         */
        public function endPricesFor(string $brandId, array $lines): array
        {
            return array_fill(0, count($lines), null);
        }
    });

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offer = landingOffers($page)[0];

            expect($offer['price']['unitAmountNet'])->toBe('19.00')
                ->and($offer['endPrice'])->toBeNull();
        });
});

/**
 * #218 (#216 draft i): the landing page is where a sales window becomes
 * visible. A type whose sale is over disappears — advertising a link that
 * refuses the submission behind it is worse than saying nothing. A type
 * whose sale has not started yet stays, announced rather than linked: that
 * announcement is the countdown the landing page owes a visitor who arrives
 * before the doors open.
 */
it('never advertises a type whose sale has ended', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())
        ->salesWindow(end: '2026-09-01 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(landingOffers($page))->toBe([]));
});

it('announces a type whose sale has not started yet instead of linking to it', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    $type = RegistrationType::factory()->forEvent($event->getKey())
        ->salesWindow(start: '2026-10-01 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($type): void {
            $offers = landingOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['name'])->toBe($type->name)
                ->and($offers[0]['onSale'])->toBeFalse()
                ->and($offers[0]['salesStart'])->toBe('2026-10-01T18:00:00+00:00');
        });
});

it('offers a type inside its window as on sale', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())
        ->salesWindow(start: '2026-09-01 18:00:00', end: '2026-10-01 18:00:00')
        ->create();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offers = landingOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['onSale'])->toBeTrue()
                ->and($offers[0]['salesStart'])->toBe('2026-09-01T18:00:00+00:00');
        });
});

it('offers a type that declares no window at all as on sale', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($event->getKey())->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offers = landingOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['onSale'])->toBeTrue()
                ->and($offers[0]['salesStart'])->toBeNull();
        });
});

/**
 * GitLab #240: `/shop` is the brand-wide, event-independent list of what can
 * be had, and it listed goods only — a visitor who arrived there saw the
 * merchandise and no way to register for anything. Registration contributes
 * its types through Commerce's `shop.index` slot, the same direction the
 * event landing page already works in: Commerce renders offers it never
 * queried, and never reads a registration table (ADR-0004).
 *
 * Prefixed helper — Pest helpers are process-global (.ai/rules/tests.md).
 */
function shopIndexOffers(AssertableInertia $page): array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'registration/ShopOffers');

    return $slot === null ? [] : $slot['props']['offers'];
}

it('offers a public registration type on the shop, named by its event', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);
    $type = RegistrationType::factory()->forEvent($event->getKey())->create(['name' => 'Weekend Ticket']);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($event, $type): void {
            $offers = shopIndexOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['name'])->toBe('Weekend Ticket')
                ->and($offers[0]['eventName'])->toBe('Wintercon')
                // Task 17c: the card links the event's name to its page.
                ->and($offers[0]['eventSlug'])->toBe('wintercon')
                ->and($offers[0]['onSale'])->toBeTrue()
                // ADR-0047 point 3: a registration joins the cart by
                // submitting first, so the card links to the form, never to
                // an "add to cart" the shop could not honour.
                ->and($offers[0]['url'])->toBe('/events/'.$event->getKey().'/register/'.$type->getKey());
        });
});

it('keeps a type whose sale is over off the shop and announces one whose sale is ahead', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);

    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Early Bird',
        'sales_end' => Carbon::now()->subDay(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Late Bird',
        'sales_start' => Carbon::parse('2026-12-01 09:00:00'),
    ]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offers = shopIndexOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['name'])->toBe('Late Bird')
                ->and($offers[0]['onSale'])->toBeFalse()
                ->and($offers[0]['salesStart'])->toBe(Carbon::parse('2026-12-01 09:00:00')->toIso8601String());
        });
});

it('never advertises a token-only type on the shop, nor another brand\'s', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->inviteOnly()->create();

    $other = $this->withBrand(Brand::factory()->create());
    $this->activateFeature('commerce:shop', $other);
    $theirEvent = Event::factory()->published()->create();
    RegistrationType::factory()->forEvent($theirEvent->getKey())->create();
    $this->withBrand($brand);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toBe([]));
});

it('drops the offers of an archived event from the shop', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Lastcon',
        'slug' => 'lastcon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subYear(),
        'archived_at' => Carbon::now()->subMonth(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create();

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toBe([]));
});

/**
 * Review finding 1 on #240: `/shop` is addressed by no event, so nothing
 * 404s in front of it the way `EventLandingController` does for the landing
 * slot. Without a gate of its own, next year's unannounced convention — its
 * name, its ticket names and its prices — stood on a public page from the
 * moment an organizer created it, because `EventCreated` projects a summary
 * straight away.
 *
 * The projection carries `public_since` now, fed by the `EventAnnounced` /
 * `EventPublished` / `EventUnpublished` contract events (ADR-0004), which is
 * `Event::isVisibleOnLanding()`'s "has left Draft" rule in the only form
 * Registration is allowed to hold it.
 */
it('never lists the offers of an event that is still a draft', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->create();
    // Exactly what `ProjectEventSummary::handleCreated()` writes for a
    // brand-new draft: a name and no `public_since`.
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Next Year',
        'slug' => 'next-year',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create(['name' => 'Weekend Ticket']);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toBe([]));
});

it('lists them once the event is published', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Next Year',
        'slug' => 'next-year',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create(['name' => 'Weekend Ticket']);

    app(ProjectEventSummary::class)->handlePublished(new EventPublished(
        eventId: (string) $event->getKey(),
        brandId: (string) $brand->getKey(),
        publishedAt: Carbon::now()->toIso8601String(),
    ));

    $this->get('/shop')
        ->assertInertia(function (AssertableInertia $page): void {
            $offers = shopIndexOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['eventName'])->toBe('Next Year');
        });
});

/**
 * Re-review N3: `UnpublishEvent` returns the event to `Announced`, not to
 * `Draft` — its landing page keeps resolving and keeps selling the same
 * registration types. `/shop` follows exactly the same rule, so the two
 * public surfaces of one brand can never disagree about one event; nothing
 * clears `public_since`, and `EventArchived` is the transition that takes
 * offers off both.
 */
it('keeps selling on both public surfaces after the event is unpublished back to announced', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    $summary = EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Next Year',
        'slug' => 'next-year',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create(['name' => 'Weekend Ticket']);

    app(ProjectEventSummary::class)->handlePublished(new EventPublished(
        eventId: (string) $event->getKey(),
        brandId: (string) $brand->getKey(),
        publishedAt: Carbon::now()->subDay()->toIso8601String(),
    ));

    app(UnpublishEvent::class)->handle($event);

    expect($event->fresh()->state)->toBe(EventLifecycleState::Announced)
        ->and($event->fresh()->isVisibleOnLanding())->toBeTrue()
        ->and($summary->fresh()->public_since)->not->toBeNull();

    // The landing page still sells it …
    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(landingOffers($page))->toHaveCount(1));

    // … so the shop must too.
    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toHaveCount(1));
});

/**
 * `EventAnnounced` counts too: `isVisibleOnLanding()` is `state !== Draft`,
 * so an announced event's landing page already sells, and dropping its
 * offers off `/shop` for the whole announced phase would be a second,
 * quieter version of the same bug.
 */
it('lists the offers of a merely announced event', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Teased Con',
        'slug' => 'teased-con',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create();

    app(ProjectEventSummary::class)->handleAnnounced(new EventAnnounced(
        eventId: (string) $event->getKey(),
        brandId: (string) $brand->getKey(),
        announcedAt: Carbon::now()->toIso8601String(),
    ));

    $this->get('/shop')
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toHaveCount(1));
});

/**
 * ADR-0017 across the slot boundary (#240, review finding 8): Registration
 * declares a net amount and a tax class as plain scalars, Commerce computes
 * the end price PAngV requires (#3) through the same `TaxCalculator` the
 * product cards on this very page run through.
 *
 * #234 moved *where*: `ShopController` used to fill `unitAmountGross` into
 * this payload on its way out, which is why `/shop` was the only public
 * surface with an end price on it. The slot computes its own now, behind
 * Platform's contract, and the amounts are unchanged — that is the point of
 * asserting them here and on the landing page above.
 */
it('carries a Commerce-computed gross amount for every priced offer on the shop', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);

    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Weekend Ticket',
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Reduced Ticket',
        'price_declaration' => new PriceDeclarationData('10.00', TaxClass::Reduced, PriceDisplay::Net),
    ]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $offers = collect(shopIndexOffers($page))->keyBy('name');

            // 19.00 net at the standard 19% rate, and 10.00 at the reduced
            // 7% — the same arithmetic `GrossPricesTest` pins for products.
            expect($offers['Weekend Ticket']['price'])
                ->toMatchArray(['unitAmountNet' => '19.00', 'display' => 'gross'])
                ->and($offers['Weekend Ticket']['endPrice'])
                ->toMatchArray(['unitAmountGross' => '22.61', 'currency' => 'EUR', 'includesTax' => true])
                ->and($offers['Reduced Ticket']['price'])
                ->toMatchArray(['unitAmountNet' => '10.00', 'display' => 'net'])
                ->and($offers['Reduced Ticket']['endPrice'])
                ->toMatchArray(['unitAmountGross' => '10.70']);
        });
});

it('leaves a free offer without a price untouched on the shop', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create(['price_declaration' => null]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(shopIndexOffers($page)[0]['price'])->toBeNull()
                ->and(shopIndexOffers($page)[0]['endPrice'])->toBeNull();
        });
});

/**
 * Branch review blocker 2: `/shop` is a listing, and a listing owes
 * `Event::scopeListedPublicly()`, not `isVisibleOnLanding()`. `Unlisted`
 * means reachable by direct link and never enumerated — an invite-only
 * track, a staff registration — and an announced one had its name, its
 * ticket names and its prices on a public page.
 *
 * The landing page is the other half of the same rule and must keep
 * selling: that is what "reachable by direct link" is for.
 */
it('never lists an unlisted event on the shop, though its landing page still sells it', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create(['visibility' => EventVisibility::Unlisted]);
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Staff Con',
        'slug' => 'staff-con',
        'visibility' => 'unlisted',
        'public_since' => Carbon::now()->subWeek(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create(['name' => 'Staff Pass']);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toBe([]));

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(landingOffers($page))->toHaveCount(1));
});

/**
 * The projection learns visibility from the contract events themselves
 * (ADR-0004), so making an event unlisted takes its offers off `/shop`
 * without anything reading Event's tables.
 */
it('drops offers off the shop when the event is made unlisted, and brings them back', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create();

    $projector = app(ProjectEventSummary::class);

    $projector->handleDetailsUpdated(new EventDetailsUpdated(
        eventId: (string) $event->getKey(),
        brandId: (string) $brand->getKey(),
        slug: $event->slug,
        name: 'Wintercon',
        visibility: 'unlisted',
    ));

    $this->get('/shop')
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toBe([]));

    $projector->handleDetailsUpdated(new EventDetailsUpdated(
        eventId: (string) $event->getKey(),
        brandId: (string) $brand->getKey(),
        slug: $event->slug,
        name: 'Wintercon',
        visibility: 'public',
    ));

    $this->get('/shop')
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toHaveCount(1));
});
