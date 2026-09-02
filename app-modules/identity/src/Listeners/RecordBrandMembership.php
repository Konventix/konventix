<?php

declare(strict_types=1);

namespace Konventix\Identity\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;

/**
 * Establishes the user↔brand relationship the first time an account touches a
 * brand (ADR-0016): registration and login both count as "seen". Idempotent —
 * the (user, brand) pair is unique and only the first contact writes.
 *
 * With no brand in context (console, tests without a request) it does nothing;
 * membership is a statement about a brand interaction, and without a brand
 * there was none.
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
}
