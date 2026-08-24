<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Event\Actions\CreateEvent;
use Konventix\Event\Data\EventData;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The registrant's own registrations. Authorization here is ownership, not a
 * capability — there is no role that grants "see your own things" — so what
 * these tests guard is that the row's owner is the only reader, and that the
 * brand scope holds even for an account that is a member of both brands
 * (ADR-0016: one account across an operator's brands).
 */
it('lists the signed-in account\'s own registrations', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $type = RegistrationType::factory()->create(['name' => 'Player']);

    $mine = Registration::factory()->forType($type)->create([
        'registrant_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('registration/my/Index')
            ->where('registrations.0.id', $mine->getKey())
            ->where('registrations.0.typeName', 'Player'));
});

it('lists nobody else\'s', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    Registration::factory()->create(); // somebody else's

    $this->actingAs($user)
        ->get('/my/registrations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations', []));
});

it('refuses another account\'s registration by ULID', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $someoneElses = Registration::factory()->create();

    $this->actingAs($user)
        ->get('/my/registrations/'.$someoneElses->getKey())
        ->assertForbidden();
});

it('sends a signed-out visitor to log in rather than showing an empty list', function (): void {
    $this->withBrand();

    $this->get('/my/registrations')->assertRedirect('/login');
});

/**
 * The account is the same across an operator's brands (ADR-0016), but the
 * registration is not: it belongs to the brand it was made with, and the
 * global scope is what keeps one brand's host from listing another's.
 */
it('shows a registration only on the brand it was made with', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $mine = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Brand::factory()->withDomain('other-brand.test')->create();

    $this->actingAs($user)
        ->get('http://other-brand.test/my/registrations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations', []));

    $this->actingAs($user)
        ->get('http://other-brand.test/my/registrations/'.$mine->getKey())
        ->assertNotFound();
});

it('names the event rather than printing its ULID', function (): void {
    $brand = $this->withBrand();

    $user = User::factory()->create();

    // Through the real Action, because the name reaches this module only via
    // Event's contract event and its own projection (ADR-0004).
    $event = app(CreateEvent::class)->handle($brand->getKey(), new EventData(
        name: 'Winter LAN',
        slug: 'winter-lan',
        description: null,
        startsAt: '2026-11-01 10:00:00',
        endsAt: '2026-11-03 18:00:00',
        timezone: 'Europe/Berlin',
        visibility: EventVisibility::Public,
        reentryPolicy: ReentryPolicy::Unlimited,
        predecessorEventId: null,
    ));

    $type = RegistrationType::factory()->create(['event_id' => $event->getKey()]);

    Registration::factory()->forType($type)->create([
        'registrant_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registrations.0.eventName', 'Winter LAN'));
});

it('shows the billing block only when there is one', function (): void {
    $this->withBrand();

    $user = User::factory()->create();

    $free = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$free->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('registration/my/Show')
            ->where('billing', null));

    $billed = Registration::factory()->create([
        'registrant_user_id' => $user->getKey(),
        'billing_recipient_name' => 'Acme GmbH',
        'billing_city' => 'Bremen',
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$billed->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('billing.recipientName', 'Acme GmbH')
            ->where('billing.city', 'Bremen'));
});

it('offers the list from the account menu, and only to a signed-in account', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('accountNav', []));

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['accountNav'])
                ->firstWhere('label', 'My registrations');

            expect($entry)->not->toBeNull()
                ->and($entry['url'])->toBe('/my/registrations');
        });
});
