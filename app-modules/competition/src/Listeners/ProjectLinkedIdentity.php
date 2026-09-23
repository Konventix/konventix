<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Competition\Models\LinkedIdentity;
use Konventix\Identity\Events\AccountsMerged;
use Konventix\Identity\Events\SocialIdentityAnnounced;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Events\SocialIdentityUnlinked;

/**
 * Keeps `competition_linked_identities` current (design §5.6): which
 * provider accounts a person has linked, so that a discipline's
 * `requires_linked_provider` can be checked without reading Identity's
 * `social_identities` (ADR-0004). The `Konventix\Identity\Events\` imports
 * here are the sanctioned exception, because events are the public contract.
 *
 * Every handler is keyed on `(user_id, provider)` and is an upsert, a delete
 * or a move. That makes the projection idempotent and replayable: running
 * `identity:announce-social-identities` any number of times converges on the
 * same rows.
 *
 * **Deliberately not gated on the Competition feature flag.** ADR-0003's
 * listener gate asks about the *event's* brand, and these events carry none:
 * the link belongs to the person, not to a brand. Competition's other
 * brand-less projections (`ProjectParticipantProfile`, the eligibility
 * projection) are ungated for the same reason, and a brand that switches
 * tournaments on must find the links already there. Otherwise people who did
 * link Steam would be refused and sent to a settings page that already shows
 * it linked. Every handler therefore always applies.
 *
 * Registered explicitly in `CompetitionServiceProvider::registerListeners()`,
 * because modular's event discovery is off and must stay off
 * (.ai/rules/listeners.md).
 */
final class ProjectLinkedIdentity
{
    /**
     * A new link. `linked_at` is the time the event was heard, because the
     * event carries no timestamp. An existing row keeps its first
     * `linked_at`, so hearing the event twice changes nothing.
     */
    public function handleLinked(SocialIdentityLinked $event): void
    {
        LinkedIdentity::query()->firstOrCreate(
            ['user_id' => $event->userId, 'provider' => $event->provider],
            ['linked_at' => now()->utc()],
        );
    }

    /**
     * The backfill. Identity's stored link time is the truth, so it
     * overwrites whatever this row held.
     */
    public function handleAnnounced(SocialIdentityAnnounced $event): void
    {
        LinkedIdentity::query()->updateOrCreate(
            ['user_id' => $event->userId, 'provider' => $event->provider],
            // Anchored to UTC before the datetime cast formats it: the cast
            // writes the digits in whatever zone the instant carries.
            ['linked_at' => CarbonImmutable::parse($event->linkedAt)->utc()],
        );
    }

    /**
     * Unlinking removes nobody
     * from a roster in 0.9.0 (design §5.6). The row goes, so the next entry
     * or join sees the account as unlinked.
     */
    public function handleUnlinked(SocialIdentityUnlinked $event): void
    {
        LinkedIdentity::query()
            ->where('user_id', $event->userId)
            ->where('provider', $event->provider)
            ->delete();
    }

    /**
     * Identity's merge moves the closed account's `SocialIdentity` rows to
     * the survivor with a plain update and emits no `SocialIdentityLinked`.
     * Without this handler the survivor would appear unlinked here while
     * Identity says it is linked. Identity refuses a merge in which both
     * accounts hold the same provider, but a row this projection kept for the
     * survivor can still collide, so a colliding row of the closed account is
     * dropped rather than moved.
     */
    public function handleAccountsMerged(AccountsMerged $event): void
    {
        $survivorProviders = LinkedIdentity::query()
            ->where('user_id', $event->survivingUserId)
            ->pluck('provider')
            ->all();

        LinkedIdentity::query()
            ->where('user_id', $event->mergedUserId)
            ->whereIn('provider', $survivorProviders)
            ->delete();

        LinkedIdentity::query()
            ->where('user_id', $event->mergedUserId)
            ->update(['user_id' => $event->survivingUserId]);
    }
}
