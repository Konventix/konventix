<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\UserAccountLocked;
use Konventix\Identity\Events\UserAccountUnlocked;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Operator-console account editing (ADR-0016: accounts are operator-global):
 * profile fields with the same email-un-verify rule the self-service form
 * applies, and lock/unlock as first producers of the account-state events.
 */
function userAdminOperator(array $permissions = [
    'platform.access-admin',
    'identity.view-users',
    'identity.manage-users',
    'identity.lock-user',
    'identity.unlock-user',
]): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.useredit.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'User Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $admin;
}

it('renders the account page with memberships and roles', function (): void {
    $admin = userAdminOperator();

    $brand = Brand::factory()->create(['name' => 'SXLan', 'slug' => 'ue-sxlan']);
    $user = User::factory()->create(['name' => 'Erika Muster']);

    (new BrandMembership([
        'user_id' => $user->getKey(),
        'first_seen_at' => now()->subDays(30),
    ]))->forceFill(['brand_id' => $brand->getKey()])->save();

    $this->actingAs($admin)
        ->get("http://admin.useredit.test/admin/users/{$user->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Edit')
            ->where('user.name', 'Erika Muster')
            ->where('user.state', 'active')
            ->where('memberships.0.brand', 'SXLan')
            ->has('roles'));
});

it('updates the account, un-verifying a changed email, and emits the contract event', function (): void {
    $admin = userAdminOperator();
    $user = User::factory()->create(['locale' => 'en']);

    Event::fake([UserProfileUpdated::class]);

    $this->actingAs($admin)
        ->put("http://admin.useredit.test/admin/users/{$user->getKey()}", [
            'name' => 'New Name',
            'email' => 'new-address@useredit.test',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new-address@useredit.test')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->locale)->toBe('de')
        ->and($user->timezone)->toBe('Europe/Berlin');

    Event::assertDispatched(
        UserProfileUpdated::class,
        fn (UserProfileUpdated $event): bool => $event->userId === $user->getKey()
            && $event->email === 'new-address@useredit.test'
            && $event->locale === 'de',
    );
});

it('locks and unlocks the account, emitting each event once', function (): void {
    $admin = userAdminOperator();
    $user = User::factory()->create();

    Event::fake([UserAccountLocked::class, UserAccountUnlocked::class]);

    $this->actingAs($admin)
        ->post("http://admin.useredit.test/admin/users/{$user->getKey()}/lock", [
            'reason' => 'chargeback fraud',
        ])
        ->assertRedirect();

    expect($user->refresh()->state)->toBe(AccountState::Locked);

    // Locking again is a no-op, not a second fact.
    $this->actingAs($admin)
        ->post("http://admin.useredit.test/admin/users/{$user->getKey()}/lock", [
            'reason' => 'again',
        ])
        ->assertRedirect();

    Event::assertDispatchedTimes(UserAccountLocked::class, 1);
    Event::assertDispatched(
        UserAccountLocked::class,
        fn (UserAccountLocked $event): bool => $event->reasonCode === 'chargeback fraud',
    );

    $this->actingAs($admin)
        ->post("http://admin.useredit.test/admin/users/{$user->getKey()}/unlock")
        ->assertRedirect();

    expect($user->refresh()->state)->toBe(AccountState::Active);
    Event::assertDispatchedTimes(UserAccountUnlocked::class, 1);
});

it('requires manage-users for the update endpoint', function (): void {
    $admin = userAdminOperator(permissions: [
        'platform.access-admin',
        'identity.view-users',
    ]);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->put("http://admin.useredit.test/admin/users/{$user->getKey()}", [
            'name' => 'X',
            'email' => 'x@useredit.test',
            'locale' => 'de',
            'timezone' => 'UTC',
        ])
        ->assertForbidden();
});

it('resets a locale to default', function (): void {
    // ADR-0068 §4: null is "follows the brand", and the console has to be
    // able to hand a person back to it — before, the rule was `required`
    // and an account that had never chosen could not be saved at all.
    $admin = userAdminOperator();
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($admin)
        ->put("http://admin.useredit.test/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'locale' => '', 'timezone' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->fresh()->locale)->toBeNull();
});

it('rejects a locale the instance does not ship', function (): void {
    $admin = userAdminOperator();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->put("http://admin.useredit.test/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'locale' => 'fr', 'timezone' => '',
        ])
        ->assertSessionHasErrors('locale');
});
