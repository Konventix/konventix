<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Agenda\Data\AgendaItemData;
use Konventix\Agenda\Enums\Permission as AgendaPermission;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for the Agenda tests. Every function here is
 * process-global and loaded for every worker from `tests/Pest.php`
 * (.ai/rules/tests.md): a helper a second test file calls cannot live
 * inside a `*Test.php`, because under `--parallel` the worker running that
 * second file never loads the first. Prefixed `agenda` to stay apart from
 * every other module's Helpers.php.
 */

/**
 * The payload of one programme entry, with the common both-ends shape as
 * the default. Deliberately `from()` and not `validateAndCreate()`: a test
 * after a reversed or impossible time needs to hand the Action something
 * the form would have refused.
 *
 * @param  array<string, mixed>  $overrides
 */
function agendaItemData(array $overrides = []): AgendaItemData
{
    return AgendaItemData::from(array_merge([
        'id' => null, 'title' => 'Lötkurs', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => '2026-11-20T15:00:00+01:00', 'endsAt' => '2026-11-20T16:30:00+01:00',
    ], $overrides));
}

/**
 * A published event of the given brand, with Agenda's own read model
 * already filled the way it is in production: by emitting Event's contract
 * events, not by writing `agenda_event_summaries` by hand. A factory row
 * emits nothing, so a test that skipped this would be testing a projection
 * that never ran.
 *
 * Fixed times and a fixed zone rather than the factory's random ones: every
 * assertion about a programme is an assertion about a wall clock (ADR-0027),
 * and `fake()->timezone()` would make them irreproducible.
 *
 * Two days, so that `ProgrammeBuilder` omitting the empty one is visible.
 *
 * `$publish` is what puts the event into a state whose programme is
 * public. A test after the draft case passes false and gets the same event
 * with nothing but `EventCreated` behind it — which is exactly how a
 * half-built programme looks in production.
 */
function agendaPublishedEvent(Brand $brand, string $timezone = 'Europe/Berlin', bool $publish = true): Event
{
    $event = Event::factory()->published()->create([
        'name' => 'Agenda Testcon',
        'slug' => 'agenda-testcon',
        'starts_at' => '2026-11-20 10:00:00',
        'ends_at' => '2026-11-21 18:00:00',
        'timezone' => $timezone,
    ]);

    event(new EventCreated(
        eventId: $event->getKey(),
        brandId: $brand->getKey(),
        slug: $event->slug,
        name: $event->name,
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-21 18:00:00',
        timezone: $timezone,
        predecessorEventId: null,
    ));

    event(new EventDaysChanged(
        eventId: $event->getKey(),
        brandId: $brand->getKey(),
        days: [
            ['day_id' => (string) Str::ulid(), 'date' => '2026-11-20', 'sequence' => 1, 'label' => 'Freitag'],
            ['day_id' => (string) Str::ulid(), 'date' => '2026-11-21', 'sequence' => 2, 'label' => 'Samstag'],
        ],
    ));

    if ($publish) {
        event(new EventPublished(
            eventId: $event->getKey(),
            brandId: $brand->getKey(),
            publishedAt: '2026-09-01 12:00:00',
        ));
    }

    return $event;
}

/**
 * One published programme entry. The instants are anchored to UTC the way
 * `SaveAgendaItem` anchors them, so a test may pass any offset it likes and
 * still store the moment it meant.
 */
function agendaPublishedItem(string $eventId, string $title, string $startsAt, ?string $endsAt = null): AgendaItem
{
    return AgendaItem::factory()->forEvent($eventId)->published()->create([
        'title' => $title,
        'starts_at' => CarbonImmutable::parse($startsAt)->utc(),
        'ends_at' => $endsAt === null ? null : CarbonImmutable::parse($endsAt)->utc(),
    ]);
}

/**
 * The operator that owns `$brand`, given an admin host and a user holding
 * the given capabilities on it, granted the way `permissions:sync`
 * materialises them (a role scoped to the operator plus the assignment) —
 * `eventsAdminOperator()`'s shape, with Agenda's own capability as the
 * default.
 *
 * The brand's own operator, not a fresh one: the editor serves only events
 * whose brand belongs to the admin host's operator. Pass `$operator` for
 * somebody else's installation. The host defaults to one distinct from
 * every other module's: `operators.admin_host` is unique.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function agendaAdminOperator(Brand $brand, ?array $permissions = null, ?Operator $operator = null, string $host = 'admin.agendaadmin.test'): array
{
    $permissions ??= ['platform.access-admin', AgendaPermission::ManageAgenda->value];

    $operator ??= Operator::query()->findOrFail($brand->operator_id);
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Agenda Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}
