<?php

declare(strict_types=1);

namespace Konventix\Identity\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Inertia\Inertia;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;

/**
 * Establishes the user↔brand relationship the first time an account touches a
 * brand (ADR-0016): registration and login both count as "seen". Idempotent —
 * the (user, brand) pair is unique and only the first contact writes.
 *
 * With no brand in context (console, tests without a request) it does nothing;
 * membership is a statement about a brand interaction, and without a brand
 * there was none.
 *
 * First contact with a *further* brand of the same operator is announced
 * once (#184, ADR-0054): the account is the operator's (ADR-0016), and
 * someone who knows two brands without knowing they share a house should
 * be told rather than left to wonder why their password worked. A session
 * flash on the response that follows the sign-in, derived from the
 * membership just written and the oldest one the account already had — no
 * new column, no new state, and nothing to show a second time because a
 * membership is only ever created once.
 *
 * `BrandContext` is resolved at call time, never constructor-injected. The
 * event dispatcher is a boot-time singleton that builds listeners with the
 * container it was constructed with; under Octane that is the worker's base
 * application, while the request runs in a cloned sandbox whose scoped
 * `BrandContext` is the one `ResolveBrand` filled. Injected at construction,
 * this listener read the base application's empty context on every request
 * and never wrote a membership — invisible to the test suite, where both
 * containers are the same object (.ai/rules/listeners.md, #142).
 */
class RecordBrandMembership
{
    public function handle(Login|Registered $event): void
    {
        $user = $event->user;
        $context = app(BrandContext::class);

        if (! $user instanceof User || ! $context->hasBrand()) {
            return;
        }

        // The brand scope constrains the lookup to the current brand and the
        // BelongsToBrand creating hook stamps brand_id — no manual brand
        // handling, per ADR-0010.
        $membership = BrandMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['first_seen_at' => now()],
        );

        if ($membership->wasRecentlyCreated) {
            $this->announceFirstContact($membership);

            // First contact: no image yet, but "member since" must be
            // replayable from the moment it exists (ruling 2).
            event(new BrandMembershipProfileUpdated(
                userId: $membership->user_id,
                brandId: $membership->brand_id,
                avatarUrl: null,
                bannerUrl: null,
                firstSeenAt: $membership->first_seen_at->toIso8601String(),
                updatedAt: now()->toIso8601String(),
            ));
        }
    }

    /**
     * "You already have an account here" — said once, on the first contact
     * with a brand that is not the first this account ever met (#184).
     *
     * The oldest membership by `first_seen_at` names the brand the person
     * is most likely to recognise; the brand *just* created is excluded by
     * asking for a different `brand_id` rather than by ordering, so a
     * membership written in the same second cannot name itself.
     *
     * `withoutBrandScope()` deliberately (ADR-0010): the whole question is
     * what this account has done on the operator's *other* brands, which
     * the scoped query can never see.
     */
    private function announceFirstContact(BrandMembership $membership): void
    {
        $earliest = BrandMembership::withoutBrandScope()
            ->where('user_id', $membership->user_id)
            ->where('brand_id', '!=', $membership->brand_id)
            ->orderBy('first_seen_at')
            ->first();

        if ($earliest === null) {
            return;
        }

        $names = Brand::query()
            ->whereIn('id', [$membership->brand_id, $earliest->brand_id])
            ->pluck('name', 'id');

        $here = $names[$membership->brand_id] ?? null;
        $first = $names[$earliest->brand_id] ?? null;

        if ($here === null || $first === null) {
            return;
        }

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Welcome to :brand. You are signed in with the account you first used at :first.', [
                'brand' => $here,
                'first' => $first,
            ]),
        ]);
    }
}
