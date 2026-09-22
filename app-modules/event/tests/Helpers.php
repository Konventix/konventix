<?php

declare(strict_types=1);

use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for the event admin console tests (ADR-0029). Every
 * function here is process-global and loaded for every worker from
 * `tests/Pest.php` (.ai/rules/tests.md): a helper a second test file calls
 * cannot live inside a `*Test.php`, because under `--parallel` the worker
 * running that second file never loads the first. Prefixed `eventsAdmin`
 * to stay apart from Venue's `venuesAdmin*` and every other module's
 * Helpers.php.
 */

/**
 * An operator with an admin host and a user holding the given
 * capabilities on it, granted the way `permissions:sync` materialises
 * them (a role scoped to the operator plus the assignment). The host is
 * fixed — `operators.admin_host` is unique, so a test needs exactly one.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
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

/**
 * An admin allowed to `event.update-event`, a brand-owned event, and the
 * `PUT /admin/events/{event}` payload that saves it unchanged — a test
 * spreads its own field on top. Sets the ambient brand for the caller.
 *
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
