<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Enums\RegistrationVisibility;
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
                ->and($offer['requiresApproval'])->toBeTrue();
        });
});

it('reports no price for a free type rather than a zero one', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    RegistrationType::factory()->forEvent($event->getKey())->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(fn (AssertableInertia $page) => expect(landingOffers($page)[0]['price'])->toBeNull());
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
    EventSummary::query()->create(['event_id' => $event->getKey(), 'name' => 'Wintercon', 'slug' => 'wintercon']);
    $type = RegistrationType::factory()->forEvent($event->getKey())->create(['name' => 'Weekend Ticket']);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($event, $type): void {
            $offers = shopIndexOffers($page);

            expect($offers)->toHaveCount(1)
                ->and($offers[0]['name'])->toBe('Weekend Ticket')
                ->and($offers[0]['eventName'])->toBe('Wintercon')
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
    EventSummary::query()->create(['event_id' => $event->getKey(), 'name' => 'Wintercon', 'slug' => 'wintercon']);

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
        'archived_at' => Carbon::now()->subMonth(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create();

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(shopIndexOffers($page))->toBe([]));
});
