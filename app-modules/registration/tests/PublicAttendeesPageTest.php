<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Registration\Attendees\PublicAttendees;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The attendees page behind the landing-page card (ADR-0064 §7): 404 before
 * "sign in", `members` asks a guest to log in, `public` does not, and the
 * names paginate. Rows come from the same projections the card reads.
 *
 * Helpers prefixed `attendeesPage*` — Pest helpers are process-global
 * (.ai/rules/tests.md). Registrants are inserted straight into the read
 * model here: the slot test already pins that the projection writes them,
 * and this file needs sixty-one of them.
 */
function attendeesPageEvent(string $attendeeList = 'public', bool $visible = true, string $visibility = EventSummary::VISIBILITY_PUBLIC): array
{
    $event = $visible ? Event::factory()->published()->create() : Event::factory()->create();

    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => $event->name,
        'slug' => $event->slug,
        'visibility' => $visibility,
        'public_since' => $visible ? Carbon::now()->subWeek() : null,
        'attendee_list_visibility' => $attendeeList,
    ]);

    return [$event, RegistrationType::factory()->forEvent($event->getKey())->create()];
}

function attendeesPageRegistrant(string $username, bool $listed = true): string
{
    return Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Ada '.$username,
        'email' => $username.'@example.test',
        'username' => $username,
        'listed' => $listed,
    ])->getKey();
}

it('answers 404 when the list is off', function (): void {
    $this->withBrand();
    [$event, $type] = attendeesPageEvent('off');
    Registration::factory()->forType($type)->forRegistrant(attendeesPageRegistrant('ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug.'/attendees')->assertNotFound();
});

it('answers 404 for an event the landing page would 404 on, even a members list — never a login redirect', function (): void {
    $this->withBrand();
    [$event] = attendeesPageEvent('members', visible: false);

    $this->get('/events/'.$event->slug.'/attendees')->assertNotFound();
});

it('resolves for an unlisted event, like its landing page', function (): void {
    $this->withBrand();
    [$event, $type] = attendeesPageEvent(visible: false, visibility: EventSummary::VISIBILITY_UNLISTED);
    Registration::factory()->forType($type)->forRegistrant(attendeesPageRegistrant('ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug.'/attendees')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('attendees.data.0.username', 'ada'));
});

it('answers 404 for an unknown slug', function (): void {
    $this->withBrand();

    $this->get('/events/no-such-event/attendees')->assertNotFound();
});

it('sends a guest to sign in for a members list on a visible event', function (): void {
    $this->withBrand();
    [$event] = attendeesPageEvent('members');

    $this->get('/events/'.$event->slug.'/attendees')->assertRedirect('/login');
});

it('shows a signed-in account the members list', function (): void {
    $this->withBrand();
    [$event, $type] = attendeesPageEvent('members');
    Registration::factory()->forType($type)->forRegistrant(attendeesPageRegistrant('ada'))->confirmed()->create();

    $this->actingAs(User::factory()->create())
        ->get('/events/'.$event->slug.'/attendees')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('event/Attendees')
            ->where('listing.eventName', $event->name)
            ->where('listing.count', 1)
            ->where('listing.namesVisible', true)
            ->where('attendees.total', 1)
            ->where('attendees.data.0.username', 'ada'));
});

it('shows a guest a public list', function (): void {
    $this->withBrand();
    [$event, $type] = attendeesPageEvent();
    Registration::factory()->forType($type)->forRegistrant(attendeesPageRegistrant('ada'))->confirmed()->create();
    Registration::factory()->forType($type)->forRegistrant(attendeesPageRegistrant('quiet', listed: false))->confirmed()->create();

    $this->get('/events/'.$event->slug.'/attendees')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('listing.count', 2)
            ->where('attendees.total', 1)
            ->where('attendees.data.0.username', 'ada'));
});

it('paginates the names by username', function (): void {
    $this->withBrand();
    [$event, $type] = attendeesPageEvent();

    foreach (range(1, PublicAttendees::PAGE_SIZE + 1) as $i) {
        Registration::factory()->forType($type)
            ->forRegistrant(attendeesPageRegistrant(sprintf('user-%03d', $i)))
            ->confirmed()
            ->create();
    }

    $this->get('/events/'.$event->slug.'/attendees')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attendees.total', PublicAttendees::PAGE_SIZE + 1)
            ->where('attendees.last_page', 2)
            ->has('attendees.data', PublicAttendees::PAGE_SIZE)
            ->where('attendees.data.0.username', 'user-001'));

    $this->get('/events/'.$event->slug.'/attendees?page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('attendees.data', 1)
            ->where('attendees.data.0.username', sprintf('user-%03d', PublicAttendees::PAGE_SIZE + 1)));
});

it('carries no display name, e-mail, organization or user id on the page either', function (): void {
    $this->withBrand();
    [$event, $type] = attendeesPageEvent();
    $userId = attendeesPageRegistrant('ada');
    Registration::factory()->forType($type)->forRegistrant($userId)->confirmed()->create([
        'organization_name' => 'Analytical Engines Ltd',
    ]);

    $this->get('/events/'.$event->slug.'/attendees')
        ->assertInertia(function (AssertableInertia $page) use ($userId): void {
            $props = $page->toArray()['props'];

            expect(array_keys($props['attendees']['data'][0]))->toBe(['username', 'profileUrl'])
                ->and($props['listing'])->not->toHaveKeys(['display_name', 'email', 'organization_name', 'userId', 'user_id']);

            $json = json_encode(['listing' => $props['listing'], 'attendees' => $props['attendees']], JSON_THROW_ON_ERROR);

            expect($json)->not->toContain($userId)
                ->and($json)->not->toContain('Ada ada')
                ->and($json)->not->toContain('@example.test')
                ->and($json)->not->toContain('Analytical Engines');
        });
});

it('links names to community profiles only where Community is active', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = attendeesPageEvent();
    Registration::factory()->forType($type)->forRegistrant(attendeesPageRegistrant('ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug.'/attendees')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('attendees.data.0.profileUrl', null));

    $this->activateModule('community', $brand);

    $this->get('/events/'.$event->slug.'/attendees')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('attendees.data.0.profileUrl', '/u/ada'));
});
