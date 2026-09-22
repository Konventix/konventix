<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Events\ParticipantAdmissionReplaced;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Presence\PresenceWindow;

/**
 * Maintains `admission_event_presence` (design §4.2) and emits the four
 * participant events the rest of the platform reacts to. Takes scalars —
 * the listeners unpack the events — and escapes the brand scope by the
 * payload's own brand id, since it runs from queue workers with no
 * ambient brand (ADR-0010).
 *
 * `ParticipantAdmitted` fires only on a false→true `hub_access`
 * transition (D-HUB-01) and `ParticipantPresenceChanged` only when
 * `last_direction` actually changes (D-HUB-02): a second scan on the same
 * open window is a fact for the check-in log, not for the hub.
 *
 * Both of those transitions are decided from the row's *announcement*
 * markers, never from the state itself. The handlers behind these events
 * are not queued — Platform's `ProjectHubAccess` runs inline inside this
 * module's queued job — so one of them throwing (a `Cache::forget` against
 * a blipping Redis, a deadlock, anything) fails the job after the row was
 * already saved. Reading the transition off the saved state would then let
 * the retry conclude that nothing changed, and the event would be lost for
 * good: a participant with no pill, or one who keeps access after a
 * revocation, in silence. So the save goes first, the event second, and the
 * marker only once the event is out — at-least-once on the way in, exactly
 * once on the way through, because every consumer upserts (ADR-0004).
 */
final class ProjectEventPresence
{
    public function __construct(private readonly PresenceWindow $window) {}

    public function checkIn(string $brandId, string $eventId, string $admissionId, string $direction, CarbonImmutable $scannedAt): ?EventPresence
    {
        // An offline batch carries the device's own offset
        // (`SynchronizeScanBatch`), and the datetime cast writes a Carbon's
        // wall-clock digits without converting it — so anchor to UTC first
        // or a +02:00 scan lands two hours late in the table.
        $scannedAt = $scannedAt->utc();

        $admission = Admission::withoutBrandScope()->where('brand_id', $brandId)->find($admissionId);

        // A claim-link holder without an account has nothing to sign in to;
        // no row, no error (design §4.2).
        if ($admission === null || $admission->holder_user_id === null) {
            return null;
        }

        $presence = $this->rowFor($brandId, $eventId, $admission->holder_user_id);

        if ($direction === 'out') {
            if ($presence === null) {
                return null;
            }

            if ($presence->last_direction !== 'out') {
                $presence->forceFill(['last_direction' => 'out', 'last_check_in_at' => $scannedAt])->save();
            }

            $this->announceDirection($presence, $eventId, $brandId, 'out', $scannedAt);

            return $presence;
        }

        $profile = EntranceEventProfile::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId)->first();
        $summary = EventSummary::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId)->first();
        $window = $profile === null
            ? ['ends_at' => null, 'day_label' => null]
            : $this->window->for($profile, $summary?->timezone, $scannedAt);

        $admitted = [
            'admission_id' => $admissionId,
            'last_check_in_at' => $scannedAt,
            'last_direction' => 'in',
            'window_ends_at' => $window['ends_at'],
            'hub_access' => true,
            'access_ended_reason' => null,
            'day_label' => $window['day_label'],
        ];

        if ($presence === null) {
            $presence = (new EventPresence)->forceFill([
                'brand_id' => $brandId,
                'event_id' => $eventId,
                'user_id' => $admission->holder_user_id,
                'first_admitted_at' => $scannedAt,
                ...$admitted,
            ]);

            try {
                // Its own transaction on purpose: from the queue that is one
                // statement, and under a caller's open transaction it is a
                // savepoint — so a refused INSERT on Postgres aborts only
                // itself, never the surrounding work.
                DB::transaction(static fn () => $presence->save());
            } catch (UniqueConstraintViolationException $collision) {
                // Two lanes scanned the same holder within a second and the
                // sibling job's INSERT won `unique(event_id, user_id)`. The
                // other job's row is *the* row — re-read it and take the
                // update path below, so this scan's instant and admission
                // still land. Whether the two events fire once or twice
                // across the pair is whatever the sibling's markers say by
                // then: a sibling that already announced silences this job,
                // and one that has not yet leaves both of them to emit. At
                // least once is the guarantee on purpose — every consumer
                // upserts (ADR-0004), and the other rounding would be the
                // lost event this marker exists to prevent.
                $presence = $this->rowFor($brandId, $eventId, $admission->holder_user_id)
                    ?? throw $collision;
            }
        }

        if (! $presence->wasRecentlyCreated) {
            $presence->forceFill($admitted)->save();
        }

        if ($presence->announced_hub_access !== true) {
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

            $presence->forceFill(['announced_hub_access' => true])->save();
        }

        $this->announceDirection($presence, $eventId, $brandId, 'in', $scannedAt);

        return $presence;
    }

    /**
     * `ParticipantPresenceChanged` for the direction the row now carries,
     * unless that direction has already been announced (D-HUB-02). The dot
     * beside the pill is only a badge, but a lost event leaves it wrong for
     * the rest of the event, so it is acknowledged like access is.
     */
    private function announceDirection(EventPresence $presence, string $eventId, string $brandId, string $direction, CarbonImmutable $at): void
    {
        if ($presence->announced_direction === $direction) {
            return;
        }

        event(new ParticipantPresenceChanged($presence->user_id, $eventId, $brandId, $direction, $at->toIso8601String()));

        $presence->forceFill(['announced_direction' => $direction])->save();
    }

    /**
     * The one row a holder has at an event (`unique(event_id, user_id)`),
     * read by the payload's own brand — never through the ambient scope.
     */
    private function rowFor(string $brandId, string $eventId, string $userId): ?EventPresence
    {
        return EventPresence::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->first();
    }

    public function endForEvent(string $brandId, string $eventId, HubAccessEndReason $reason, CarbonImmutable $at): int
    {
        return $this->end(
            EventPresence::withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId),
            $reason,
            $at,
        );
    }

    /**
     * The admission that let a person in is gone (design §4.2) — but a
     * person can hold several for one event, a crew badge beside a bought
     * ticket, and losing one of them is not losing the event (ruling,
     * 2026-09-22). So the row moves over to an admission of theirs that
     * still stands and access continues; only when none is left does it
     * end with `revoked`. The lookup excludes the revoked admission by id
     * rather than trusting its state, since the caller may run before or
     * after that state change is visible.
     *
     * A re-point emits `ParticipantAdmissionReplaced`, never
     * `ParticipantHubAccessEnded`: access did not end, but the admission id
     * every consumer mirrors did change, and one of them builds a link out
     * of it. Returns the number of rows that actually *lost* access, so a
     * re-point counts as nothing ended.
     */
    public function endForAdmission(string $brandId, string $admissionId, HubAccessEndReason $reason, CarbonImmutable $at): int
    {
        $ended = 0;

        $rows = EventPresence::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('admission_id', $admissionId)
            ->where($this->unsettled(...))
            ->get();

        foreach ($rows as $presence) {
            // A row the query only picked up to finish an interrupted
            // ending has no access left to carry anywhere: it goes through
            // `endRow()`, which re-emits and settles it. Only a row that
            // still has access looks for a survivor.
            $survivor = $presence->hub_access
                ? Admission::withoutBrandScope()
                    ->where('brand_id', $brandId)
                    ->where('event_id', $presence->event_id)
                    ->where('holder_user_id', $presence->user_id)
                    ->whereKeyNot($admissionId)
                    ->where('state', '!=', AdmissionState::Revoked)
                    ->orderBy('id')
                    ->first()
                : null;

            if ($survivor !== null) {
                // Access is untouched, but the id consumers mirror is not:
                // Platform's `platform_hub_access` carries `admission_id`
                // and Resources' hub link is built out of it, so a silent
                // re-point would leave that link naming the admission that
                // was just revoked.
                //
                // Emitted *before* the re-point, the other way round from
                // the transitions above: this row is found by the revoked
                // admission's id, so moving it first is what would hide it
                // from the retry. A listener that throws leaves the row
                // where it was and the retry finds it again; a redelivery
                // costs a consumer one idempotent write.
                event(new ParticipantAdmissionReplaced(
                    userId: $presence->user_id,
                    eventId: $presence->event_id,
                    brandId: $presence->brand_id,
                    admissionId: $survivor->id,
                    replacedAt: $at->toIso8601String(),
                ));

                $presence->forceFill(['admission_id' => $survivor->id])->save();

                continue;
            }

            if ($this->endRow($presence, $reason, $at)) {
                $ended++;
            }
        }

        return $ended;
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
     * "Not settled yet": the row still has access, or it lost access
     * without the platform ever being told. Everything else — access
     * already gone *and* already announced — is done with.
     *
     * @param  Builder<EventPresence>  $query
     */
    private function unsettled(Builder $query): void
    {
        $query->where('hub_access', true)->orWhere('announced_hub_access', true);
    }

    /**
     * Rows that still have access, plus the rows whose loss of access was
     * never announced — a job that saved `hub_access = false` and then died
     * before its event got out would otherwise never be picked up again,
     * because it no longer matches "still has access". Ending twice still
     * emits once, now by the marker rather than by the flag, and the count
     * only counts the rows that actually lost access here.
     *
     * Chunked by id because the loop mutates the very column the query
     * filters on — offset paging would skip every other page.
     *
     * @param  Builder<EventPresence>  $query
     */
    private function end(Builder $query, HubAccessEndReason $reason, CarbonImmutable $at): int
    {
        $ended = 0;

        $query->where($this->unsettled(...))->orderBy('id')->chunkById(200, function (Collection $rows) use ($reason, $at, &$ended): void {
            foreach ($rows as $presence) {
                if ($this->endRow($presence, $reason, $at)) {
                    $ended++;
                }
            }
        });

        return $ended;
    }

    /**
     * One row loses access and the platform hears of it — the same writes
     * whichever path (event closed, window expired, admission revoked)
     * decided it: the state first, the event second, the acknowledgement
     * last. Returns whether this call is what took the access away, so a
     * pass that only finishes an interrupted one is not counted twice.
     */
    private function endRow(EventPresence $presence, HubAccessEndReason $reason, CarbonImmutable $at): bool
    {
        $lost = $presence->hub_access;

        if ($lost) {
            $presence->forceFill(['hub_access' => false, 'access_ended_reason' => $reason])->save();
        }

        if ($presence->announced_hub_access !== false) {
            event(new ParticipantHubAccessEnded($presence->user_id, $presence->event_id, $presence->brand_id, $reason->value, $at->toIso8601String()));

            $presence->forceFill(['announced_hub_access' => false])->save();
        }

        return $lost;
    }
}
