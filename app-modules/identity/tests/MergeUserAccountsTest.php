<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\AccountsMerged;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * #81: an operator-performed merge, `ManageUsers`-gated the same as the rest
 * of account administration. See `Actions\MergeUserAccounts`'s docblock for
 * why this is an operator action rather than self-service, which account
 * survives, and — at length — what this slice deliberately does not move.
 */
function mergeAdminOperator(array $permissions = [
    'platform.access-admin',
    'identity.view-users',
    'identity.manage-users',
]): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.mergeusers.test'])->save();

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

function mergedMailPushed(string $templateKey): array
{
    return Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    )->all();
}

/**
 * `AuthMailer::resolveBrandId()` falls back to the account's own earliest
 * `BrandMembership` off the operator admin host (ADR-0029: no ambient brand
 * there) — this stands in for what `RecordBrandMembership` writes on a real
 * login, which a factory-created account never went through.
 */
function givenAMergeTestBrandMembership(User $user, Brand $brand): void
{
    (new BrandMembership(['user_id' => $user->getKey(), 'first_seen_at' => now()]))
        ->forceFill(['brand_id' => $brand->getKey()])
        ->save();
}

it('merges the closed account into the survivor: identities move, credentials are cleared, and both are notified', function (): void {
    $admin = mergeAdminOperator();
    Event::fake([AccountsMerged::class]);
    Queue::fake();

    $brand = Brand::factory()->create();
    $survivor = User::factory()->create(['email' => 'survivor@mergeusers.test']);
    $closed = User::factory()->create(['email' => 'closed@mergeusers.test']);
    givenAMergeTestBrandMembership($survivor, $brand);
    givenAMergeTestBrandMembership($closed, $brand);
    SocialIdentity::factory()->for($closed)->create(['provider' => 'steam', 'provider_user_id' => '76561198000000200']);

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'closed@mergeusers.test',
        ])
        ->assertRedirect();

    $survivor->refresh();
    $closed->refresh();

    expect($survivor->state)->toBe(AccountState::Active)
        ->and($survivor->socialIdentities()->where('provider', 'steam')->exists())->toBeTrue()
        ->and($closed->state)->toBe(AccountState::Merged)
        ->and($closed->merged_into_user_id)->toBe($survivor->getKey())
        ->and($closed->password)->toBeNull()
        ->and($closed->two_factor_secret)->toBeNull()
        // Left alone, deliberately (Actions\MergeUserAccounts docblock): the
        // address stays reserved and the row stays a legible audit trail.
        ->and($closed->email)->toBe('closed@mergeusers.test')
        ->and($closed->name)->not->toBeNull();

    Event::assertDispatched(
        AccountsMerged::class,
        fn (AccountsMerged $event): bool => $event->survivingUserId === $survivor->getKey()
            && $event->mergedUserId === $closed->getKey()
            && $event->performedByUserId === $admin->getKey(),
    );

    $survivorMail = mergedMailPushed('identity.account-merged-survivor');
    expect($survivorMail)->toHaveCount(1);
    expect($survivorMail[0]->message->recipientId)->toBe($survivor->getKey());
    expect($survivorMail[0]->message->payload->toArray()['otherEmail'])->toBe('closed@mergeusers.test');

    $closedMail = mergedMailPushed('identity.account-merged-closed');
    expect($closedMail)->toHaveCount(1);
    expect($closedMail[0]->message->recipientId)->toBe($closed->getKey());
    expect($closedMail[0]->message->payload->toArray()['survivorEmail'])->toBe('survivor@mergeusers.test');
});

it('refuses to merge an account into itself', function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->create(['email' => 'solo@mergeusers.test']);

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'solo@mergeusers.test',
        ])
        ->assertSessionHasErrors('email');

    expect($survivor->refresh()->state)->toBe(AccountState::Active);
});

it('refuses to merge in an address that names no account', function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->create();

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'nobody-here@mergeusers.test',
        ])
        ->assertSessionHasErrors('email');
});

it('refuses to merge in an account that was already merged elsewhere', function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->create();
    $elsewhere = User::factory()->create();
    $alreadyMerged = User::factory()->merged($elsewhere)->create(['email' => 'already-merged@mergeusers.test']);

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'already-merged@mergeusers.test',
        ])
        ->assertSessionHasErrors('email');

    expect($alreadyMerged->refresh()->merged_into_user_id)->toBe($elsewhere->getKey());
});

it('refuses to merge into a survivor that is not active', function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->locked()->create();
    $other = User::factory()->create(['email' => 'other@mergeusers.test']);

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'other@mergeusers.test',
        ])
        ->assertSessionHasErrors('email');

    expect($other->refresh()->state)->toBe(AccountState::Active);
});

/**
 * `social_identities` carries a `(user_id, provider)` unique index — without
 * this guard the merge would either silently orphan one Steam connection or
 * fail with a raw constraint violation instead of a field error.
 */
it('refuses to merge when both accounts have the same provider connected', function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->create();
    $other = User::factory()->create(['email' => 'other-steam@mergeusers.test']);

    SocialIdentity::factory()->for($survivor)->create(['provider' => 'steam', 'provider_user_id' => '76561198000000301']);
    SocialIdentity::factory()->for($other)->create(['provider' => 'steam', 'provider_user_id' => '76561198000000302']);

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'other-steam@mergeusers.test',
        ])
        ->assertSessionHasErrors('email');

    expect($survivor->refresh()->state)->toBe(AccountState::Active)
        ->and($other->refresh()->state)->toBe(AccountState::Active)
        ->and($survivor->socialIdentities()->count())->toBe(1)
        ->and($other->socialIdentities()->count())->toBe(1);
});

it('requires manage-users to merge accounts', function (): void {
    $admin = mergeAdminOperator(permissions: [
        'platform.access-admin',
        'identity.view-users',
    ]);
    $survivor = User::factory()->create();
    $other = User::factory()->create(['email' => 'gated@mergeusers.test']);

    $this->actingAs($admin)
        ->post("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/merge", [
            'email' => 'gated@mergeusers.test',
        ])
        ->assertForbidden();

    expect($other->refresh()->state)->toBe(AccountState::Active);
});

it("shows the survivor's edit page which accounts it absorbed", function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->create();
    $closed = User::factory()->merged($survivor)->create(['email' => 'absorbed@mergeusers.test']);

    $this->actingAs($admin)
        ->get("http://admin.mergeusers.test/admin/users/{$survivor->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Edit')
            ->where('user.mergedInto', null)
            ->where('mergedAccounts.0.id', $closed->getKey())
            ->where('mergedAccounts.0.email', 'absorbed@mergeusers.test'));
});

it("shows a merged account's edit page which account absorbed it", function (): void {
    $admin = mergeAdminOperator();
    $survivor = User::factory()->create(['email' => 'kept@mergeusers.test']);
    $closed = User::factory()->merged($survivor)->create();

    $this->actingAs($admin)
        ->get("http://admin.mergeusers.test/admin/users/{$closed->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Edit')
            ->where('user.state', 'merged')
            ->where('user.mergedInto.id', $survivor->getKey())
            ->where('user.mergedInto.email', 'kept@mergeusers.test'));
});
