<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Presence\PresenceWindow;

/**
 * Maintains `admission_event_presence` (design §4.2) and emits the three
 * participant events the rest of the platform reacts to. Takes scalars —
 * the listeners unpack the events — and escapes the brand scope by the
 * payload's own brand id, since it runs from queue workers with no
 * ambient brand (ADR-0010).
 *
 * `ParticipantAdmitted` fires only on a false→true `hub_access`
 * transition (D-HUB-01) and `ParticipantPresenceChanged` only when
 * `last_direction` actually changes (D-HUB-02): a second scan on the same
 * open window is a fact for the check-in log, not for the hub.
 */
final class ProjectEventPresence
{
    public function __construct(private readonly PresenceWindow $window) {}

    public function checkIn(string $brandId, string $eventId, string $admissionId, string $direction, CarbonImmutable $scannedAt): ?EventPresence
    {
        $admission = Admission::withoutBrandScope()->where('brand_id', $brandId)->find($admissionId);

        // A claim-link holder without an account has nothing to sign in to;
        // no row, no error (design §4.2).
        if ($admission === null || $admission->holder_user_id === null) {
            return null;
        }

        $presence = EventPresence::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('user_id', $admission->holder_user_id)
            ->first();

        if ($direction === 'out') {
            if ($presence === null || $presence->last_direction === 'out') {
                return $presence;
            }

            $presence->forceFill(['last_direction' => 'out', 'last_check_in_at' => $scannedAt])->save();
            event(new ParticipantPresenceChanged($presence->user_id, $eventId, $brandId, 'out', $scannedAt->toIso8601String()));

            return $presence;
        }

        $profile = EntranceEventProfile::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId)->first();
        $summary = EventSummary::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId)->first();
        $window = $profile === null
            ? ['ends_at' => null, 'day_label' => null]
            : $this->window->for($profile, $summary?->timezone, $scannedAt);

        $hadAccess = $presence?->hub_access === true;
        $wasIn = $presence?->last_direction === 'in';

        $presence ??= (new EventPresence)->forceFill([
            'brand_id' => $brandId,
            'event_id' => $eventId,
            'user_id' => $admission->holder_user_id,
            'first_admitted_at' => $scannedAt,
        ]);

        $presence->forceFill([
            'admission_id' => $admissionId,
            'last_check_in_at' => $scannedAt,
            'last_direction' => 'in',
            'window_ends_at' => $window['ends_at'],
            'hub_access' => true,
            'access_ended_reason' => null,
            'day_label' => $window['day_label'],
        ])->save();

        if (! $hadAccess) {
            event(new ParticipantAdmitted(
                userId: $presence->user_id,
                eventId: $eventId,
                brandId: $brandId,
                admissionId: $admissionId,
                admittedAt: $scannedAt->toIso8601String(),
                windowEndsAt: $window['ends_at']?->toIso8601String(),
                dayLabel: $window['day_label'],
                eventName: $summary?->name,
                eventSlug: $summary?->slug,
            ));
        }

        if (! $wasIn) {
            event(new ParticipantPresenceChanged($presence->user_id, $eventId, $brandId, 'in', $scannedAt->toIso8601String()));
        }

        return $presence;
    }

    public function endForEvent(string $brandId, string $eventId, HubAccessEndReason $reason, CarbonImmutable $at): int
    {
        return $this->end(
            EventPresence::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId),
            $reason,
            $at,
        );
    }

    public function endForAdmission(string $brandId, string $admissionId, HubAccessEndReason $reason, CarbonImmutable $at): int
    {
        return $this->end(
            EventPresence::withoutBrandScope()->where('brand_id', $brandId)->where('admission_id', $admissionId),
            $reason,
            $at,
        );
    }

    /**
     * The once-per-day sweep (D-HUB-01): every open window whose end has
     * passed closes with `day_ended`, across all brands — the scheduler
     * has no ambient brand either.
     */
    public function closeExpiredWindows(CarbonImmutable $now): int
    {
        return $this->end(
            EventPresence::withoutBrandScope()->whereNotNull('window_ends_at')->where('window_ends_at', '<=', $now),
            HubAccessEndReason::DayEnded,
            $now,
        );
    }

    /**
     * Only rows that still have access flip, so ending twice emits once.
     * Chunked by id because the loop mutates the very column the query
     * filters on — offset paging would skip every other page.
     *
     * @param  Builder<EventPresence>  $query
     */
    private function end(Builder $query, HubAccessEndReason $reason, CarbonImmutable $at): int
    {
        $ended = 0;

        $query->where('hub_access', true)->orderBy('id')->chunkById(200, function (Collection $rows) use ($reason, $at, &$ended): void {
            foreach ($rows as $presence) {
                $presence->forceFill(['hub_access' => false, 'access_ended_reason' => $reason])->save();
                event(new ParticipantHubAccessEnded($presence->user_id, $presence->event_id, $presence->brand_id, $reason->value, $at->toIso8601String()));
                $ended++;
            }
        });

        return $ended;
    }
}
