<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Closure;
use Illuminate\Support\Collection;
use Konventix\Identity\Actions\LockUserAccount;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The brand's people. Accounts are operator-global (ADR-0016); the
 * brand-scoped fact is the membership row.
 *
 * Every created account emits `UserRegistered`, which is the whole point of
 * doing this here rather than in a factory call: three modules keep a read
 * model of accounts they may not query directly (ADR-0004) — Registration's
 * `Registrant`, Content's `Author` and Messaging's `Recipient` — and all
 * three are fed by that event alone. Without it the registrations list shows
 * bare ULIDs, news carries no bylines, and an id-only send throws for want
 * of an address.
 *
 * Idempotent: tops up to the requested membership count instead of adding a
 * fresh cohort on every run.
 */
final class DemoUserSeeder
{
    public function __construct(private readonly LockUserAccount $lockAccount) {}

    /**
     * How many accounts this brand still needs. Public so a console caller
     * can size its progress bar without the seeder knowing what a console is.
     */
    public function pendingCount(int $target): int
    {
        return max(0, $target - BrandMembership::query()->count());
    }

    /**
     * @param  Closure(): void|null  $onCreated  called once per created account, for progress reporting
     * @return Collection<int, User>
     */
    public function handle(Brand $brand, int $count, ?Closure $onCreated = null): Collection
    {
        $existing = BrandMembership::query()->with('user')->get();
        $missing = max(0, $count - $existing->count());

        /** @var Collection<int, User> $existingUsers */
        $existingUsers = $existing->pluck('user')->filter()->values();

        // Guarded, not clamped: range(1, 0) counts *down* and would create two
        // accounts for a brand that needs none.
        /** @var Collection<int, User> $created */
        $created = $missing === 0 ? collect() : collect(range(1, $missing))->map(function () use ($brand, $onCreated): User {
            $user = $this->seedMember($brand);

            if ($onCreated !== null) {
                $onCreated();
            }

            return $user;
        });

        $this->announceExistingMembers($brand, $existingUsers);

        $members = $created->merge($existingUsers)->values();

        $this->seedLockedAccount($members);

        return $members;
    }

    /**
     * Re-announces accounts this run did not create.
     *
     * This is the backfill both Messaging's and Content's TODO ask for, in the
     * one place a demo can honestly do it: a database seeded before the
     * projections existed — or before this slice emitted anything — has
     * accounts no read model has ever heard of, and nothing else will ever
     * tell it about them. The projections are idempotent upserts keyed on the
     * user's ULID, so re-announcing converges on the rows a replay would have
     * produced rather than duplicating them.
     *
     * @param  Collection<int, User>  $members
     */
    private function announceExistingMembers(Brand $brand, Collection $members): void
    {
        $members->each(fn (User $user) => $this->announce($brand, $user));
    }

    private function seedMember(Brand $brand): User
    {
        $user = User::factory()->create();

        // Brand context is set: the scope constrains the lookup and the
        // BelongsToBrand hook stamps brand_id (ADR-0010).
        BrandMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['first_seen_at' => now()->subDays(random_int(0, 365))],
        );

        $this->announce($brand, $user);

        return $user;
    }

    /**
     * Identity's contract event, exactly as `CreateNewUser` emits it — the
     * read models downstream converge on the same rows a real signup would
     * have produced.
     */
    private function announce(Brand $brand, User $user): void
    {
        event(new UserRegistered(
            userId: $user->getKey(),
            brandId: $brand->getKey(),
            email: $user->email,
            displayName: $user->name,
            locale: $user->locale,
            registeredAt: $user->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ));
    }

    /**
     * One locked account per brand, so the user console has the state its
     * lock/unlock buttons exist for. The last member is picked rather than
     * the first: the first few hold the seeded brand roles.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedLockedAccount(Collection $members): void
    {
        /** @var User|null $candidate */
        $candidate = $members->last();

        if ($candidate === null || $candidate->state === AccountState::Locked) {
            return;
        }

        $alreadyLocked = $members->contains(
            fn (User $member): bool => $member->state === AccountState::Locked,
        );

        if ($alreadyLocked) {
            return;
        }

        $this->lockAccount->handle($candidate, 'demo-abuse-report');
    }
}
