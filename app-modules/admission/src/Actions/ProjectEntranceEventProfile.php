<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\EntranceEventProfile;

/**
 * Maintains Admission's read model of the Event facts the door needs offline
 * (ADR-0033). Takes scalars, never the event objects — the thin listeners
 * unpack them, mirroring `IssueAdmissionsForRegistration`.
 *
 * Both source payloads carry their *complete* current value, so each writer
 * replaces its own field outright. That is what makes a replay converge:
 * patching would let a re-delivered older payload interleave with a newer one
 * and leave the door deciding on a calendar that never existed.
 *
 * `withoutBrandScope()` scoped to the payload's own `brandId` is the named
 * escape hatch (ADR-0010): projections run from queue workers with no ambient
 * brand.
 */
final class ProjectEntranceEventProfile
{
    /**
     * @param  list<array{day_id: string, date: string, sequence: int, label: string|null}>  $days
     */
    public function days(string $eventId, string $brandId, array $days): EntranceEventProfile
    {
        $profile = $this->profileFor($eventId, $brandId);

        $profile->days = $days;
        $profile->save();

        return $profile;
    }

    public function reentryPolicy(string $eventId, string $brandId, string $reentryPolicy): EntranceEventProfile
    {
        $profile = $this->profileFor($eventId, $brandId);

        // An unrecognised value means Event grew a case this module has not
        // learned yet. Falling back to Unlimited keeps the door open rather
        // than locking everyone out of a running event over a vocabulary
        // mismatch — ADR-0033's "never degrades to a closed door", applied to
        // the projection as well as to the feed.
        $profile->reentry_policy = ReentryPolicy::tryFrom($reentryPolicy) ?? ReentryPolicy::Unlimited;
        $profile->save();

        return $profile;
    }

    private function profileFor(string $eventId, string $brandId): EntranceEventProfile
    {
        $profile = EntranceEventProfile::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        $profile = new EntranceEventProfile(['event_id' => $eventId]);
        // brand_id from the payload, not ambient context (ADR-0010).
        $profile->forceFill(['brand_id' => $brandId]);

        return $profile;
    }
}
