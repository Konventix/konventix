<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Competition\Models\LinkedIdentity;
use Konventix\Identity\Events\AccountsMerged;
use Konventix\Identity\Events\SocialIdentityAnnounced;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Events\SocialIdentityUnlinked;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;

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
 * **The gate (ADR-0003) has no event brand to ask about.** None of these
 * events carries a brand, because the link belongs to the person and not to
 * a brand. So the question is whether *any* brand on the deployment runs
 * tournaments. If none does, the handlers that write a row short-circuit and
 * a deployment without Competition stores nothing. The unlink handler always
 * deletes: removing a row never stores anything, and a stale "linked" row is
 * the worse failure. Links made while the module was off everywhere come
 * back through `identity:announce-social-identities` once a brand switches
 * it on (docs/operations.md).
 *
 * Registered explicitly in `CompetitionServiceProvider::registerListeners()`,
 * because modular's event discovery is off and must stay off
 * (.ai/rules/listeners.md). `ModuleGate` is resolved inside the handler and
 * never constructor-injected.
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
        if (! $this->gateIsOpen()) {
            return;
        }

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
        if (! $this->gateIsOpen()) {
            return;
        }

        LinkedIdentity::query()->updateOrCreate(
            ['user_id' => $event->userId, 'provider' => $event->provider],
            // Anchored to UTC before the datetime cast formats it: the cast
            // writes the digits in whatever zone the instant carries.
            ['linked_at' => CarbonImmutable::parse($event->linkedAt)->utc()],
        );
    }

    /**
     * Ungated on purpose (see the class docblock). Unlinking removes nobody
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
        if (! $this->gateIsOpen()) {
            return;
        }

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

    /**
     * Whether any brand on the deployment has `competition` switched on. Asked
     * per brand through `ModuleGate::isActiveForBrand()`, which never touches
     * the ambient brand context, because these events arrive on a social
     * callback, a console command or a merge, and none of those carries one.
     */
    private function gateIsOpen(): bool
    {
        $gate = app(ModuleGate::class);

        foreach (Brand::query()->pluck('id') as $brandId) {
            if ($gate->isActiveForBrand('competition', (string) $brandId)) {
                return true;
            }
        }

        return false;
    }
}
