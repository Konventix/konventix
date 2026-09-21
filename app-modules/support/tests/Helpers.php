<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

/**
 * Helpers more than one file of this module's suite calls. Pest loads every
 * module's `tests/Helpers.php` once per process, which is what makes them
 * exist in every worker of a parallel run — a function declared inside a
 * `*Test.php` file exists only in the process that loaded that file, and
 * the names are process-global across all modules (`.ai/rules/tests.md`).
 * Hence the `support` prefix on every one of them.
 */

/**
 * An account Messaging can actually address.
 *
 * The recipient read model has no factory: rows exist only through
 * `ProjectRecipient`'s projection, so a test that needs `SendMessage` to
 * resolve an address emits the Identity event rather than inserting one.
 */
function supportRecipient(string $brandId): User
{
    $user = User::factory()->create();

    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        username: $user->username,
        locale: $user->locale,
        registeredAt: now()->toIso8601String(),
    ));

    return $user;
}

/**
 * Somebody holding capabilities on one team — a brand for Support's own
 * capabilities, the *operator* for `platform.access-admin`, which is how
 * console roles are teamed in this project (`MaterializeOperatorAdminRole`).
 *
 * @param  list<string>  $permissions
 */
function supportHolder(string $teamId, array $permissions, string $roleName = 'Support Desk'): User
{
    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => $roleName,
        'guard_name' => 'web',
        'brand_id' => $teamId,
    ]);
    $role->givePermissionTo($permissions);

    $user = supportRecipient($teamId);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $teamId,
    ]);

    return $user;
}

/**
 * An operator answering on its own admin host, so a test can reach the
 * console surface of the report channel (ADR-0029, ADR-0063).
 */
function supportOperator(string $adminHost): Operator
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $adminHost])->save();

    return $operator;
}

/**
 * The body the dialog posts (design §6.2/§6.4), with only the parts a test
 * cares about overridden.
 *
 * `context` defaults to the client half that a real browser sends, so a
 * test asserting the trust boundary can override one field of it and say
 * exactly what it means.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supportSubmission(array $overrides = []): array
{
    return array_replace([
        'categories' => ['function'],
        'message' => 'The pay button does nothing on my phone and then the page reloads.',
        'email' => 'visitor@example.test',
        'contactConsent' => true,
        'context' => array_replace([
            'pageCorrelationId' => '01K5MZ6V4N8YQ2W3J7R9T1X0AB',
            'url' => 'http://localhost/',
            'previousUrl' => 'http://localhost/events',
            'previousCorrelationId' => '01K5MZ6V4N8YQ2W3J7R9T1X0AC',
            'recentVisits' => [],
            'viewport' => ['width' => 390, 'height' => 844, 'dpr' => 3],
            'clientSentAt' => '2026-09-21T10:05:00+00:00',
            'lastClientError' => null,
        ], $overrides['context'] ?? []),
    ], array_diff_key($overrides, ['context' => null]));
}
