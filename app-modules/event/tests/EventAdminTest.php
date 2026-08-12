<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;
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
