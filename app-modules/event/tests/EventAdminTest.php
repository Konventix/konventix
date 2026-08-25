<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event as EventFacade;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: the event admin console — index and per-event dashboard — exists
 * only on the operator's admin host, behind `event.view-events`. The
 * dashboard composes its own header with contributions from other modules'
 * `event.admin.dashboard` UI slot (ADR-0003 enforcement point 3,
 * docs/decisions/0003). Registration and Admission are Core modules
 * (`gateable = false`), so their cards are always present — no Pennant
 * activation needed for this test to see them.
 */
function eventsAdminOperator(array $permissions = ['platform.access-admin', 'event.view-events']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.eventsadmin.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('404s the events index on a brand host', function (): void {
    eventsAdminOperator();

    $this->get('http://localhost/admin/events')->assertNotFound();
});

it('redirects an unauthenticated admin-host request to login', function (): void {
    eventsAdminOperator();

    $this->get('http://admin.eventsadmin.test/admin/events')
        ->assertRedirect('http://admin.eventsadmin.test/login');
});

it('403s an authenticated admin without the view-events capability', function (): void {
    [, $admin] = eventsAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events')
        ->assertForbidden();
});

it('lists events across every brand on the index', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->withBrand();
    Event::factory()->create(['name' => 'Alpha Con']);

    $brandTwo = Brand::factory()->withDomain('event-admin-brand-two.test')->create();
    $this->withBrand($brandTwo);
    Event::factory()->create(['name' => 'Beta Con']);

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Index')
            ->has('events.data', 2));
});

it('shows the dashboard header and every core module\'s slot contribution', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->withBrand();
    $event = Event::factory()->create([
        'name' => 'Alpha Con',
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Dashboard')
            ->where('event.id', $event->id)
            ->where('event.name', 'Alpha Con')
            ->where('event.startsAt', '2027-06-15 10:00')
            ->where('event.timezone', 'Europe/Berlin')
            ->where('slots', function (Collection $slots): bool {
                $components = $slots->pluck('component')->all();

                return in_array('registration/EventStatsCard', $components, true)
                    && in_array('admission/EventStatsCard', $components, true);
            }));
});

it('404s the dashboard for an unknown event ulid', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events/01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->assertNotFound();
});

/**
 * @return array{0: User, 1: Event, 2: array<string, mixed>}
 */
function eventsAdminEditable(): array
{
    [, $admin] = eventsAdminOperator(['platform.access-admin', 'event.update-event']);

    test()->withBrand();
    $event = Event::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-16 18:00:00',
    ]);

    $payload = [
        'name' => $event->name,
        'slug' => $event->slug,
        'description' => $event->description,
        'startsAt' => $event->starts_at->format('Y-m-d H:i:s'),
        'endsAt' => $event->ends_at->format('Y-m-d H:i:s'),
        'timezone' => $event->timezone,
        'visibility' => $event->visibility->value,
        'reentryPolicy' => $event->reentry_policy->value,
    ];

    return [$admin, $event, $payload];
}

// Regression: BrandScope hardens to zero rows on the admin host, so the day
// sync used to see no existing days, re-insert the submitted date, and die on
// unique(event_id, date) — while the edit page rendered an empty day list.
it('keeps event days across repeated saves on the admin host', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $payload['days'] = [['date' => '2027-06-15', 'label' => 'Day 1']];

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", $payload)
        ->assertRedirect();

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", $payload)
        ->assertRedirect();

    expect(EventDay::withoutBrandScope()->where('event_id', $event->id)->count())->toBe(1);

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Edit')
            ->has('days', 1)
            ->where('days.0.date', '2027-06-15')
            ->where('days.0.label', 'Day 1'));
});

it('emits EventDaysChanged with the full day set when saving on the admin host', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $payload['days'] = [
        ['date' => '2027-06-15', 'label' => null],
        ['date' => '2027-06-16', 'label' => 'Finals'],
    ];

    EventFacade::fake([EventDaysChanged::class]);

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", $payload)
        ->assertRedirect();

    EventFacade::assertDispatched(
        EventDaysChanged::class,
        fn (EventDaysChanged $changed): bool => count($changed->days) === 2
            && $changed->days[0]['date'] === '2027-06-15'
            && $changed->days[1]['date'] === '2027-06-16',
    );
});

it('lists assigned venues on the admin edit page', function (): void {
    [$admin, $event] = eventsAdminEditable();

    $venue = Venue::factory()->create(['brand_id' => $event->brand_id]);

    $this->actingAs($admin)
        ->post("http://admin.eventsadmin.test/admin/events/{$event->id}/venues", [
            'venue_id' => $venue->id,
            'is_primary' => true,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Edit')
            ->has('venues', 1)
            ->where('venues.0.id', $venue->id)
            ->where('venues.0.isPrimary', true));
});

it('defaults the brand filter to the console selection and lets the list clear it', function (): void {
    [, $admin] = eventsAdminOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    Event::factory()->create(['name' => 'SXLan 2027']);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    Event::factory()->create(['name' => 'Xpertise 2027']);

    $this->actingAs($admin)
        ->put('http://admin.eventsadmin.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    // The events list is what the event selector points into, so only the
    // brand half of the selection applies to it.
    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $sxlan->getKey())
            ->count('events.data', 1)
            ->where('events.data.0.name', 'SXLan 2027'));

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events?brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('events.data', 2));
});
