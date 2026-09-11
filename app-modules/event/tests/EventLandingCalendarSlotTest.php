<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;

/**
 * Spec §7: Event's own contribution to `event.landing` — the landing page
 * has no action button today, so this is the first entry the slot ever
 * carries for a public visitor. No SSR process runs in this environment
 * (the same note `RegistrationMineSeatSlotTest.php` makes for Resources'
 * seat-picker link), so the rendered anchor itself is not assertable here
 * — `npm run build` and `npm run types:check` cover the template
 * compiling against this prop shape.
 */
function eventLandingCalendarSlot(AssertableInertia $page): ?array
{
    return collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'event/CalendarLink');
}

it('contributes a calendar link carrying the event slug', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($event): void {
            $slot = eventLandingCalendarSlot($page);

            expect($slot)->not->toBeNull()
                ->and($slot['module'])->toBe('event')
                ->and($slot['props']['eventSlug'])->toBe($event->slug);
        });
});
