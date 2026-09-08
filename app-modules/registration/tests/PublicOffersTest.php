<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
use Konventix\Registration\Enums\RegistrationVisibility;
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
