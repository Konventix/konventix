<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Models\Brand;

/**
 * The brand's public event list (`event.index`) — the only enumeration of
 * events a visitor gets, and the destination of the topbar's "Events" entry.
 *
 * Its rules are deliberately not the landing page's: `isVisibleOnLanding()`
 * answers "may this link resolve", `scopeListedPublicly()` answers "may this
 * be enumerated", and unlisted events are the case where the two disagree.
 */
function publicIndexNames(AssertableInertia $page, string $section): array
{
    return collect($page->toArray()['props'][$section])->pluck('name')->all();
}

it('lists a published event', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $this->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('event/Index')
            ->where('upcoming.0.name', $event->name)
            ->where('upcoming.0.slug', $event->slug)
            ->where('upcoming.0.timezone', $event->timezone));
});

it('omits an unlisted event, which keeps its landing page', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->unlisted()->create();

    $this->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect([
            ...publicIndexNames($page, 'upcoming'),
            ...publicIndexNames($page, 'past'),
        ])->not->toContain($event->name));

    // The point of "unlisted": still reachable by the link its holder has.
    $this->get('/events/'.$event->slug)->assertOk();
});

it('omits draft and archived events', function (): void {
    $this->withBrand();

    $draft = Event::factory()->create();
    $archived = Event::factory()->archived()->create();

    $this->get('/events')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($draft, $archived): void {
            $names = [...publicIndexNames($page, 'upcoming'), ...publicIndexNames($page, 'past')];

            expect($names)->not->toContain($draft->name)
                ->and($names)->not->toContain($archived->name);
        });
});

it('keeps a cancelled event listed, because a visitor who planned around it must be told', function (): void {
    $this->withBrand();

    $event = Event::factory()->cancelled()->create();

    $this->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('upcoming.0.name', $event->name)
            ->where('upcoming.0.state', 'cancelled'));
});

it('splits upcoming from past on the real instant, not the wall clock', function (): void {
    $this->withBrand();

    // Both events end at the same wall-clock time. Only the zone separates
    // them, and only one of them has actually finished (ADR-0027) — a
    // partition done in SQL over the stored strings would get this wrong.
    $wallClock = now()->addHours(6);

    $finished = Event::factory()->published()->create([
        'name' => 'Already Over',
        'starts_at' => $wallClock->copy()->subHours(2)->format('Y-m-d H:i:s'),
        'ends_at' => $wallClock->format('Y-m-d H:i:s'),
        'timezone' => 'Pacific/Kiritimati', // UTC+14: that wall clock has passed.
    ]);

    $ahead = Event::factory()->published()->create([
        'name' => 'Still Ahead',
        'starts_at' => $wallClock->copy()->subHours(2)->format('Y-m-d H:i:s'),
        'ends_at' => $wallClock->format('Y-m-d H:i:s'),
        'timezone' => 'Pacific/Niue', // UTC-11: that wall clock is still to come.
    ]);

    $this->get('/events')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($finished, $ahead): void {
            expect(publicIndexNames($page, 'past'))->toContain($finished->name)
                ->and(publicIndexNames($page, 'upcoming'))->toContain($ahead->name);
        });
});

it('carries the primary venue city, so the list says where before a click', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create(['city' => 'Bremen']);

    Event::factory()->published()->forVenue($venue)->create();

    $this->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('upcoming.0.venue', 'Bremen'));
});

it('lists no other brand\'s events', function (): void {
    $this->withBrand();

    Event::factory()->published()->create();

    Brand::factory()->withDomain('other-brand.test')->create();

    $this->get('http://other-brand.test/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('upcoming', [])
            ->where('past', []));
});

it('offers the list from the brand topbar', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['nav'])->firstWhere('label', 'Events');

            expect($entry)->not->toBeNull()
                ->and($entry['url'])->toBe('/events');
        });
});
