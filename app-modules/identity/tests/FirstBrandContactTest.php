<?php

declare(strict_types=1);

use Inertia\Support\SessionKey;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * #184 / ADR-0054: the account is the operator's, so meeting a second brand
 * of the same operator is not a new account — and the person is told so,
 * once, the moment `RecordBrandMembership` writes the membership.
 *
 * Session flash, no new state: the notice is derived from the membership
 * that was just created and the oldest one the account already had.
 *
 * Helper names prefixed `firstContact*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function firstContactSignIn(User $user, string $host): void
{
    test()->post("http://{$host}/login", [
        'email' => $user->email,
        'password' => 'secret-password-1',
    ]);
}

function firstContactMembership(User $user, Brand $brand, int $yearsAgo): void
{
    // `brand_id` is stamped by BelongsToBrand from the ambient brand, never
    // mass-assigned — the membership's own writer works the same way.
    app(BrandContext::class)->set($brand);

    BrandMembership::query()->create([
        'user_id' => $user->getKey(),
        'first_seen_at' => now()->subYears($yearsAgo),
    ]);

    app(BrandContext::class)->forget();
}

function firstContactFlash(): ?array
{
    $flash = session(SessionKey::FLASH_DATA);

    return is_array($flash) ? ($flash['toast'] ?? null) : null;
}

it('announces the shared account the first time a second brand is met', function (): void {
    $operator = Operator::factory()->create(['name' => 'Acme Events']);
    $first = Brand::factory()->withDomain('first.test')->create(['name' => 'First Con', 'operator_id' => $operator->getKey()]);
    Brand::factory()->withDomain('second.test')->create(['name' => 'Second Con', 'operator_id' => $operator->getKey()]);

    $user = User::factory()->create(['password' => 'secret-password-1']);

    // The account already knows the first brand, and knew it earlier.
    firstContactMembership($user, $first, yearsAgo: 1);

    firstContactSignIn($user, 'second.test');

    $toast = firstContactFlash();

    expect($toast)->not->toBeNull()
        ->and($toast['message'])->toContain('Second Con')
        ->and($toast['message'])->toContain('First Con');
});

it('shows the notice once — a second login on the same brand says nothing', function (): void {
    $operator = Operator::factory()->create();
    $first = Brand::factory()->withDomain('first.test')->create(['name' => 'First Con', 'operator_id' => $operator->getKey()]);
    Brand::factory()->withDomain('second.test')->create(['name' => 'Second Con', 'operator_id' => $operator->getKey()]);

    $user = User::factory()->create(['password' => 'secret-password-1']);
    firstContactMembership($user, $first, yearsAgo: 1);

    firstContactSignIn($user, 'second.test');
    $this->post('http://second.test/logout');
    firstContactSignIn($user, 'second.test');

    expect(firstContactFlash())->toBeNull();
});

it('says nothing on the very first brand an account ever meets', function (): void {
    Brand::factory()->withDomain('only.test')->create(['name' => 'Only Con']);

    $user = User::factory()->create(['password' => 'secret-password-1']);

    firstContactSignIn($user, 'only.test');

    expect(BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->count())->toBe(1)
        ->and(firstContactFlash())->toBeNull();
});

it('names the oldest brand the account knows, not the most recent one', function (): void {
    $operator = Operator::factory()->create();
    $oldest = Brand::factory()->withDomain('oldest.test')->create(['name' => 'Oldest Con', 'operator_id' => $operator->getKey()]);
    $middle = Brand::factory()->withDomain('middle.test')->create(['name' => 'Middle Con', 'operator_id' => $operator->getKey()]);
    Brand::factory()->withDomain('newest.test')->create(['name' => 'Newest Con', 'operator_id' => $operator->getKey()]);

    $user = User::factory()->create(['password' => 'secret-password-1']);

    foreach ([[$oldest, 3], [$middle, 1]] as [$brand, $yearsAgo]) {
        firstContactMembership($user, $brand, $yearsAgo);
    }

    firstContactSignIn($user, 'newest.test');

    expect(firstContactFlash()['message'])->toContain('Oldest Con')
        ->and(firstContactFlash()['message'])->not->toContain('Middle Con');
});

/**
 * Whole-branch review finding 13. `users` has no `operator_id` — accounts
 * are global to the instance — so a membership query with no operator
 * filter would tell a person on operator B's login page that they "first
 * used" the account at operator A's brand. That is one operator's brand
 * name inside another operator's auth page, and the opposite of what #184
 * and ADR-0054 set out to say ("which operator holds it").
 */
it('says nothing about another operator\'s brand (#184, ADR-0054)', function (): void {
    $theirs = Operator::factory()->create(['name' => 'Their Events']);
    $ours = Operator::factory()->create(['name' => 'Our Events']);

    $foreign = Brand::factory()->withDomain('theirs.test')->create([
        'name' => 'Their Con',
        'operator_id' => $theirs->getKey(),
    ]);
    Brand::factory()->withDomain('ours.test')->create([
        'name' => 'Our Con',
        'operator_id' => $ours->getKey(),
    ]);

    $user = User::factory()->create(['password' => 'secret-password-1']);
    firstContactMembership($user, $foreign, yearsAgo: 1);

    firstContactSignIn($user, 'ours.test');

    expect(firstContactFlash())->toBeNull();
});

it('names the oldest brand of this operator, skipping an older one of another (#184)', function (): void {
    $theirs = Operator::factory()->create();
    $ours = Operator::factory()->create();

    $foreign = Brand::factory()->withDomain('theirs.test')->create([
        'name' => 'Their Con',
        'operator_id' => $theirs->getKey(),
    ]);
    $sibling = Brand::factory()->withDomain('sibling.test')->create([
        'name' => 'Sibling Con',
        'operator_id' => $ours->getKey(),
    ]);
    Brand::factory()->withDomain('ours.test')->create([
        'name' => 'Our Con',
        'operator_id' => $ours->getKey(),
    ]);

    $user = User::factory()->create(['password' => 'secret-password-1']);
    firstContactMembership($user, $foreign, yearsAgo: 5);
    firstContactMembership($user, $sibling, yearsAgo: 1);

    firstContactSignIn($user, 'ours.test');

    expect(firstContactFlash()['message'])->toContain('Sibling Con')
        ->and(firstContactFlash()['message'])->not->toContain('Their Con');
});
