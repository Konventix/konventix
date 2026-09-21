<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The attendee-list card on the event landing page (ADR-0064): Registration's
 * second `event.landing` contribution. What is under test is the privacy
 * rule as much as the markup — confirmed only, one entry per person, the
 * count under the event's switch alone, a name only with the person's own
 * opt-in, and never anything but the username.
 *
 * Rows are arranged by emitting Identity's events, never by inserting
 * read-model rows directly — except for the one case that *is* a read-model
 * gap (a registrant this module has not been told a username for).
 * Helpers prefixed `publicAttendees*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function publicAttendeesCard(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'registration/EventAttendees');

    return $slot === null ? null : $slot['props']['listing'];
}

function publicAttendeesRegistrant(Brand $brand, string $username, bool $listed = true): string
{
    $userId = (string) Str::ulid();

    event(new UserRegistered(
        userId: $userId,
        brandId: $brand->getKey(),
        email: $username.'@example.test',
        displayName: 'Ada '.Str::title($username),
        username: $username,
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));
    event(new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: 'Ada '.Str::title($username),
        username: $username,
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        listedInAttendees: $listed,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    return $userId;
}

/**
 * A published event whose summary this module holds — exactly what
 * `EventCreated` + `EventPublished` + `EventAttendeeListPolicyChanged`
 * project — plus one public type to register for.
 *
 * @return array{0: Event, 1: RegistrationType}
 */
function publicAttendeesEvent(string $attendeeList = 'public'): array
{
    $event = Event::factory()->published()->create();

    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => $event->name,
        'slug' => $event->slug,
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
        'attendee_list_visibility' => $attendeeList,
    ]);

    return [$event, RegistrationType::factory()->forEvent($event->getKey())->create()];
}

it('renders nothing when the event keeps its list off', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent('off');
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(publicAttendeesCard($page))->toBeNull());
});

it('names a confirmed registrant who opted in, and counts them', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($event): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(1)
                ->and($card['namesVisible'])->toBeTrue()
                ->and($card['namedCount'])->toBe(1)
                ->and($card['attendees'])->toBe([['username' => 'ada', 'profileUrl' => null]])
                ->and($card['url'])->toBe('/events/'.$event->slug.'/attendees')
                ->and($card['eventUrl'])->toBe('/events/'.$event->slug);
        });
});

it('neither counts nor names a submitted or an approved registration', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'submitted'))->create();
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'approved'))->approved()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(0)
                ->and($card['attendees'])->toBe([]);
        });
});

it('drops a registrant the moment their registration is cancelled', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    $registration = Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(fn (AssertableInertia $page) => expect(publicAttendeesCard($page)['count'])->toBe(1));

    app(CancelRegistration::class)->handle($registration, null, 'registrant-withdrew');

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(0)
                ->and($card['attendees'])->toBe([]);
        });
});

it('counts a registrant who did not opt in but never names them', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'quiet', listed: false))->confirmed()->create();
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'loud'))->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(2)
                ->and($card['namedCount'])->toBe(1)
                ->and(array_column($card['attendees'], 'username'))->toBe(['loud']);
        });
});

it('counts a registrant whose username it has not been told, without naming them', function (): void {
    $this->withBrand();
    [$event, $type] = publicAttendeesEvent();

    // A row projected before the username joined the read model, opted in
    // but with nothing to show — the replay fills it; until then: counted.
    $registrant = Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'listed' => true,
    ]);
    Registration::factory()->forType($type)->forRegistrant($registrant->getKey())->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(1)
                ->and($card['namedCount'])->toBe(0)
                ->and($card['attendees'])->toBe([]);
        });
});

it('lists a person with two confirmed registrations once', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    $second = RegistrationType::factory()->forEvent($event->getKey())->create();
    $userId = publicAttendeesRegistrant($brand, 'ada');
    Registration::factory()->forType($type)->forRegistrant($userId)->confirmed()->create();
    Registration::factory()->forType($second)->forRegistrant($userId)->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(1)
                ->and($card['namedCount'])->toBe(1)
                ->and($card['attendees'])->toHaveCount(1);
        });
});

it('orders names by username and links on after a dozen', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();

    foreach (range(1, 13) as $i) {
        Registration::factory()->forType($type)
            ->forRegistrant(publicAttendeesRegistrant($brand, sprintf('user-%02d', 14 - $i)))
            ->confirmed()
            ->create();
    }

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(13)
                ->and($card['namedCount'])->toBe(13)
                ->and($card['attendees'])->toHaveCount(12)
                ->and($card['attendees'][0]['username'])->toBe('user-01')
                ->and($card['attendees'][11]['username'])->toBe('user-12');
        });
});

/**
 * ADR-0064 §3: the payload is the username and nothing else — not the
 * display name, not the e-mail, not the billing party, not the account's
 * ULID. Pinned on the serialized card, not on the Data class, so a field
 * added anywhere on the way out is caught.
 */
it('carries no display name, e-mail, organization or user id anywhere in the payload', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    $userId = publicAttendeesRegistrant($brand, 'ada');
    Registration::factory()->forType($type)->forRegistrant($userId)->confirmed()->create([
        'organization_name' => 'Analytical Engines Ltd',
        'billing_email' => 'billing@example.test',
    ]);

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page) use ($userId): void {
            $card = publicAttendeesCard($page);

            expect($card)->not->toHaveKeys(['display_name', 'displayName', 'email', 'organization_name', 'organizationName', 'userId', 'user_id', 'id'])
                ->and(array_keys($card['attendees'][0]))->toBe(['username', 'profileUrl']);

            $json = json_encode($card, JSON_THROW_ON_ERROR);

            expect($json)->not->toContain($userId)
                ->and($json)->not->toContain('Ada Ada')
                ->and($json)->not->toContain('@example.test')
                ->and($json)->not->toContain('Analytical Engines');
        });
});

it('shows a guest the count but not the names of a members-only list', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent('members');
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['count'])->toBe(1)
                ->and($card['namesVisible'])->toBeFalse()
                ->and($card['namedCount'])->toBe(0)
                ->and($card['attendees'])->toBe([]);
        });

    $this->actingAs(User::factory()->create())
        ->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $card = publicAttendeesCard($page);

            expect($card['namesVisible'])->toBeTrue()
                ->and(array_column($card['attendees'], 'username'))->toBe(['ada']);
        });
});

/**
 * ADR-0064 §5: the link to `/u/{username}` exists only where Community is
 * active for the brand, and is written as a literal path — Registration
 * imports neither Community's routes nor its models.
 */
it('links a name to the community profile only where Community is active', function (): void {
    $brand = $this->withBrand();
    [$event, $type] = publicAttendeesEvent();
    Registration::factory()->forType($type)->forRegistrant(publicAttendeesRegistrant($brand, 'ada'))->confirmed()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(fn (AssertableInertia $page) => expect(publicAttendeesCard($page)['attendees'][0]['profileUrl'])->toBeNull());

    $this->activateModule('community', $brand);

    $this->get('/events/'.$event->slug)
        ->assertInertia(fn (AssertableInertia $page) => expect(publicAttendeesCard($page)['attendees'][0]['profileUrl'])->toBe('/u/ada'));
});
