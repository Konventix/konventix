<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run; names are process-global across all
 * modules and therefore prefixed `competition` (.ai/rules/tests.md).
 * Helpers declared inside a *Test.php (`compTestEligible`) stay private to
 * that file.
 *
 * This file is created here, once, and only ever appended to: a redeclared
 * name is a fatal error in every worker.
 */

/**
 * An operator with an admin host and a console user holding the given
 * capabilities. Competition's console lives on the operator host (ADR-0029),
 * where no brand resolves — every test that needs a brand-owned row after
 * such a request creates it first.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function competitionOperator(array $permissions = [
    'platform.access-admin',
    'competition.view-competitions',
    'competition.manage-competitions',
    'competition.manage-disciplines',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.tournaments.test'])->save();

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
 * Stands in for whichever module owns events: the console's event selector
 * is fed through Platform's `ConsoleEventSources`, and Competition keeps no
 * event read model of its own (ADR-0004). Returns the event's ULID.
 */
function competitionConsoleEvent(Brand $brand, string $name = 'SXLan 2027', ?string $eventId = null): string
{
    $eventId ??= (string) Str::ulid();

    app(ConsoleEventSources::class)->add(static fn (?string $brandId): array => [
        ['id' => $eventId, 'name' => $name, 'brandId' => (string) $brand->getKey()],
    ]);

    return $eventId;
}

/**
 * Somebody who may enter (design §5.1, D-CMP-05): a confirmed registration
 * for the event, arriving the only sanctioned way — through Registration's
 * contract events, never by writing Competition's read model directly
 * (ADR-0004, ADR-0006). Returns the user's ULID.
 *
 * Lives here rather than in a test file because two of this module's files
 * call it: under `--parallel` each worker loads only the files it was handed,
 * so a helper declared in one `*Test.php` does not exist in the worker
 * running the other (.ai/rules/tests.md).
 */
function competitionEligibleUser(string $eventId, string $brandId, ?string $userId = null): string
{
    $userId ??= (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    event(new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'on_submit',
        submittedAt: now()->toIso8601String(),
    ));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));

    return $userId;
}

/**
 * A name in Competition's own read model (design §10), projected the only
 * way it ever is: from Identity's `UserProfileUpdated`. No test in this
 * module writes `competition_participant_profiles` by hand, and none imports
 * Identity's `User` — which is exactly what the third acceptance criterion of
 * #297 asks for.
 */
function competitionParticipantName(string $userId, string $displayName, string $username): void
{
    event(new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: $displayName,
        username: $username,
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        listedInAttendees: true,
        locale: 'de',
        updatedAt: now()->toIso8601String(),
    ));
}
