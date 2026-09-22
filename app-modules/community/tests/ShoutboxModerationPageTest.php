<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Community\Actions\LockRoom;
use Konventix\Community\Actions\MuteParticipant;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\ReportMessage;
use Konventix\Community\Data\MuteData;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Enums\Permission;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\EventPresence;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\HubAccess;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The moderation console (design §8, ADR-0029): the report queue, the mute
 * list and the lock switch for one event's wall, on the operator admin host.
 *
 * **D-COM-04 is the reason this file exists.** Moderation is gated on
 * `ModerateCommunity` and nothing else: the organizer in these tests was
 * never admitted to anything, and the first test asserts that before it
 * removes, mutes and locks.
 *
 * Every brand-owned row is created **before** the first admin-host request:
 * that host resolves no brand and hardens `BrandScope` to nothing
 * (`.ai/rules/tests.md`). The host is in the URL, never in
 * `withServerVariables()`.
 *
 * Helpers are prefixed `shoutboxDesk*` — Pest helpers are process-global.
 */

/**
 * The operator that owns `$brand`, given an admin host and a user holding
 * the given capabilities on it — granted the way `permissions:sync`
 * materialises them, `agendaAdminOperator()`'s shape.
 *
 * The desk only serves rooms whose brand belongs to the admin host's own
 * operator, so the operator here is the brand's, not a fresh one. The host
 * is this file's own: `operators.admin_host` is unique.
 *
 * @param  list<string>|null  $permissions
 * @return array{0: Operator, 1: User}
 */
function shoutboxDeskOperator(Brand $brand, ?array $permissions = null): array
{
    $operator = Operator::query()->findOrFail($brand->operator_id);

    return [$operator, shoutboxDeskGrant($operator, 'admin.shoutbox.test', $permissions)];
}

/**
 * A second operator with its own admin host and a full desk grant — the
 * moderator of somebody else's installation.
 *
 * @return array{0: Operator, 1: User}
 */
function shoutboxDeskForeignOperator(): array
{
    $operator = Operator::factory()->create();

    return [$operator, shoutboxDeskGrant($operator, 'admin.shoutbox-elsewhere.test', null)];
}

/**
 * @param  list<string>|null  $permissions
 */
function shoutboxDeskGrant(Operator $operator, string $host, ?array $permissions): User
{
    $permissions ??= ['platform.access-admin', Permission::ModerateCommunity->value];

    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Shoutbox Desk',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $organizer = User::factory()->create();
    $organizer->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $organizer;
}

function shoutboxDeskUrl(Operator $operator, string $eventId, string $path = ''): string
{
    return "http://{$operator->admin_host}/admin/events/{$eventId}/shoutbox{$path}";
}

it('lets an organizer with ModerateCommunity and no admission remove, mute and lock (D-COM-04)', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    $authorId = (string) Str::ulid();
    CommunityProfile::query()->create(['id' => $authorId, 'username' => 'krawall', 'display_name' => 'Krawall']);

    $line = app(PostMessage::class)->handle($room, $authorId, new PostMessageData('Unfug'));
    $report = app(ReportMessage::class)->handle($line, (string) Str::ulid(), new ReportData('Beleidigend'));

    [$operator, $organizer] = shoutboxDeskOperator($brand);

    // Never scanned in, never admitted: neither Platform's hub access nor
    // Community's badge has heard of this person.
    expect(HubAccess::withoutBrandScope()->where('user_id', $organizer->id)->exists())->toBeFalse()
        ->and(EventPresence::withoutBrandScope()->where('user_id', $organizer->id)->exists())->toBeFalse();

    $this->actingAs($organizer)
        ->get(shoutboxDeskUrl($operator, $eventId))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/community/Shoutbox')
            ->where('desk.eventId', $eventId)
            ->where('desk.room.id', $room->id)
            ->where('desk.room.lockedAt', null)
            ->has('desk.reports', 1)
            ->where('desk.reports.0.id', $report->id)
            ->where('desk.reports.0.authorDisplayName', 'Krawall')
            ->where('desk.reports.0.body', 'Unfug')
            ->where('desk.reports.0.note', 'Beleidigend')
            ->has('desk.lines', 1)
            ->has('desk.mutes', 0));

    $this->actingAs($organizer)
        ->delete(shoutboxDeskUrl($operator, $eventId, "/messages/{$line->id}"), ['reason' => 'Insult'])
        ->assertRedirect(shoutboxDeskUrl($operator, $eventId));

    $this->actingAs($organizer)
        ->post(shoutboxDeskUrl($operator, $eventId, "/mutes/{$authorId}"), ['reason' => 'Spam', 'minutes' => 10])
        ->assertRedirect(shoutboxDeskUrl($operator, $eventId));

    $this->actingAs($organizer)
        ->post(shoutboxDeskUrl($operator, $eventId, '/lock'), ['reason' => 'Es ist drei Uhr nachts.'])
        ->assertRedirect(shoutboxDeskUrl($operator, $eventId));

    $this->actingAs($organizer)
        ->post(shoutboxDeskUrl($operator, $eventId, "/reports/{$report->id}/resolve"), ['state' => 'resolved'])
        ->assertRedirect(shoutboxDeskUrl($operator, $eventId));

    $line->refresh();
    $room->refresh();
    $report->refresh();
    $mute = RoomMute::withoutBrandScope()->sole();

    expect($line->isRemoved())->toBeTrue()
        ->and($line->deleted_by_user_id)->toBe($organizer->id)
        ->and($line->deleted_reason)->toBe('Insult')
        ->and($mute->room_id)->toBe($room->id)
        ->and($mute->user_id)->toBe($authorId)
        ->and($mute->muted_by_user_id)->toBe($organizer->id)
        ->and($mute->reason)->toBe('Spam')
        ->and($mute->expires_at?->isFuture())->toBeTrue()
        ->and($room->locked_at)->not->toBeNull()
        ->and($room->locked_by_user_id)->toBe($organizer->id)
        ->and($room->locked_reason)->toBe('Es ist drei Uhr nachts.')
        ->and($report->state)->toBe(ReportState::Resolved)
        ->and($report->resolved_by_user_id)->toBe($organizer->id);

    // The desk reads back what it did: the removed body with its reason,
    // the mute, the lock, and an empty queue.
    $this->actingAs($organizer)
        ->get(shoutboxDeskUrl($operator, $eventId))
        ->assertInertia(fn (Assert $page) => $page
            ->where('desk.room.lockedReason', 'Es ist drei Uhr nachts.')
            ->has('desk.reports', 0)
            ->has('desk.mutes', 1)
            ->where('desk.mutes.0.userId', $authorId)
            ->where('desk.mutes.0.reason', 'Spam')
            ->where('desk.lines.0.body', 'Unfug')
            ->where('desk.lines.0.removedReason', 'Insult'));
});

it('reverses its own verbs: unlock, lift a mute, dismiss a report', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);
    $authorId = (string) Str::ulid();

    $line = app(PostMessage::class)->handle($room, $authorId, new PostMessageData('Grenzwertig'));
    $report = app(ReportMessage::class)->handle($line, (string) Str::ulid(), new ReportData);
    app(MuteParticipant::class)->handle($room, $authorId, (string) Str::ulid(), 'Abkühlen', null);
    app(LockRoom::class)->handle($room, (string) Str::ulid());

    [$operator, $organizer] = shoutboxDeskOperator($brand);

    $this->actingAs($organizer)->delete(shoutboxDeskUrl($operator, $eventId, '/lock'))->assertRedirect();
    $this->actingAs($organizer)->delete(shoutboxDeskUrl($operator, $eventId, "/mutes/{$authorId}"))->assertRedirect();
    $this->actingAs($organizer)
        ->post(shoutboxDeskUrl($operator, $eventId, "/reports/{$report->id}/resolve"), ['state' => 'dismissed'])
        ->assertRedirect();

    expect($room->refresh()->locked_at)->toBeNull()
        ->and($report->refresh()->state)->toBe(ReportState::Dismissed)
        ->and($line->refresh()->isRemoved())->toBeFalse();

    $this->actingAs($organizer)
        ->get(shoutboxDeskUrl($operator, $eventId))
        ->assertInertia(fn (Assert $page) => $page
            ->where('desk.room.lockedAt', null)
            ->has('desk.mutes', 0)
            ->has('desk.reports', 0));
});

it('refuses the desk to an admin without ModerateCommunity', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    [$operator, $admin] = shoutboxDeskOperator($brand, ['platform.access-admin']);

    $this->actingAs($admin)->get(shoutboxDeskUrl($operator, $eventId))->assertForbidden();
    $this->actingAs($admin)->post(shoutboxDeskUrl($operator, $eventId, '/lock'))->assertForbidden();

    expect($room->refresh()->locked_at)->toBeNull();
});

/**
 * `MuteData::from($request)` is the form's validation: the reason the muted
 * person will read is capped at `MuteData::MAX_REASON_LENGTH` at the form,
 * where the operator gets a field error — not only inside the Action,
 * where they would get an exception.
 */
it('enforces the mute reason and duration at the form', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    Room::factory()->create(['subject_id' => $eventId]);
    $authorId = (string) Str::ulid();

    [$operator, $organizer] = shoutboxDeskOperator($brand);
    $mute = shoutboxDeskUrl($operator, $eventId, "/mutes/{$authorId}");

    $this->actingAs($organizer)
        ->from(shoutboxDeskUrl($operator, $eventId))
        ->post($mute, ['reason' => str_repeat('x', MuteData::MAX_REASON_LENGTH + 1), 'minutes' => 10])
        ->assertSessionHasErrors('reason');

    $this->actingAs($organizer)
        ->from(shoutboxDeskUrl($operator, $eventId))
        ->post($mute, ['reason' => '', 'minutes' => 10])
        ->assertSessionHasErrors('reason');

    $this->actingAs($organizer)
        ->from(shoutboxDeskUrl($operator, $eventId))
        ->post($mute, ['reason' => 'Spam', 'minutes' => 0])
        ->assertSessionHasErrors('minutes');

    expect(RoomMute::withoutBrandScope()->count())->toBe(0);
});

it('finds no desk for a brand with community switched off', function (): void {
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);

    [$operator, $organizer] = shoutboxDeskOperator($brand);

    $this->actingAs($organizer)->get(shoutboxDeskUrl($operator, $eventId))->assertNotFound();
    $this->actingAs($organizer)->post(shoutboxDeskUrl($operator, $eventId, '/lock'))->assertNotFound();

    expect($room->refresh()->locked_at)->toBeNull();
});

/**
 * The console never creates a room: it knows the event only by the id in
 * its URL. An event whose wall nobody opened yet is an empty desk to read
 * and nothing to act on.
 */
it('renders an empty desk for a wall nobody has opened, and refuses to act on it', function (): void {
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();

    [$operator, $organizer] = shoutboxDeskOperator($brand);

    $this->actingAs($organizer)
        ->get(shoutboxDeskUrl($operator, $eventId))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/community/Shoutbox')
            ->where('desk.eventId', $eventId)
            ->where('desk.room', null)
            ->has('desk.reports', 0));

    $this->actingAs($organizer)->post(shoutboxDeskUrl($operator, $eventId, '/lock'))->assertNotFound();

    expect(Room::withoutBrandScope()->count())->toBe(0);
});

/**
 * The room is found by event id across the whole installation, so the desk
 * itself has to refuse a room whose brand belongs to another operator — a
 * moderator on operator Y's admin host must not read or act on operator
 * X's wall, even holding `ModerateCommunity` and knowing the event id.
 */
it('hides another operator\'s wall from a desk holding ModerateCommunity', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $eventId = (string) Str::ulid();
    $room = Room::factory()->create(['subject_id' => $eventId]);
    $line = app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData('Nur für die eigene Orga'));

    [$elsewhere, $stranger] = shoutboxDeskForeignOperator();

    expect($elsewhere->getKey())->not->toBe($brand->operator_id);

    $this->actingAs($stranger)->get(shoutboxDeskUrl($elsewhere, $eventId))->assertNotFound();
    $this->actingAs($stranger)->post(shoutboxDeskUrl($elsewhere, $eventId, '/lock'))->assertNotFound();
    $this->actingAs($stranger)
        ->delete(shoutboxDeskUrl($elsewhere, $eventId, "/messages/{$line->id}"), ['reason' => 'Fremd'])
        ->assertNotFound();

    expect($room->refresh()->locked_at)->toBeNull()
        ->and($line->refresh()->isRemoved())->toBeFalse();
});
