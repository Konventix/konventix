<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EntranceEventProfile;

/**
 * The shared check-in core (attendee-list design §3.1, D-ATT-08): every
 * admission-level check and the one writer of `check_ins`.
 *
 * The door reaches it through `RecordScan`, once the token has verified and
 * the admission is resolved and locked; the console reaches it without a
 * token at all. Both write the same row and raise the same event, so every
 * consumer of `AdmissionCheckedIn` — presence, the hub, Commerce's refund
 * block, Registration's door verification, Competition's door presence —
 * reacts to a console check-in exactly as to a scan.
 *
 * The checks keep `RecordScan`'s order, which mirrors what an offline device
 * can decide for itself (ADR-0033): outside every event day, then the
 * token's day scope, then admission validity, then re-entry. Keeping the
 * server in the device's order is what makes both give the same reason code.
 *
 * `AdmissionCheckedIn` and `CheckInRejected` are dispatched synchronously
 * inside the transaction, as they always were at the door. Moving them to
 * `DB::afterCommit()` would change door behaviour, which the split forbids;
 * their listeners that do real work are queued `afterCommit` themselves.
 */
final class RecordCheckIn
{
    public function __construct(
        private readonly EvaluateAdmissionValidity $evaluateValidity,
    ) {}

    /**
     * The caller holds the admission's row lock. The brand is the
     * admission's own.
     *
     * `$admittedDayIds` is the token's day scope and `[]` means every day,
     * with the same reading as `AdmissionTokenPayload::admitsOnDay()`. The
     * console passes nothing: an admission stores no day restriction.
     *
     * `$conditionsChecked` is what was decided about the holder's entry
     * conditions (ADR-0066 §6.4): `[{key, outcome}]`. It rides on an accepted
     * check-in and on `AdmissionCheckedIn`, never on a rejection, and it
     * changes nothing about the decision — which is why it is read after
     * every other check rather than before any of them.
     *
     * @param  list<array{key: string, outcome: string}>  $conditionsChecked
     * @param  list<string>  $admittedDayIds
     */
    public function handle(
        Admission $admission,
        string $eventId,
        string $clientScanId,
        CarbonInterface $at,
        ?Checkpoint $checkpoint = null,
        ?EntranceDevice $device = null,
        string $direction = 'in',
        array $conditionsChecked = [],
        ?string $actorUserId = null,
        array $admittedDayIds = [],
    ): CheckIn {
        $brandId = $admission->brand_id;

        return DB::transaction(function () use ($admission, $brandId, $eventId, $clientScanId, $at, $checkpoint, $device, $direction, $conditionsChecked, $actorUserId, $admittedDayIds): CheckIn {
            // Idempotent per `client_scan_id` — a device replaying its buffer
            // after reconnecting is the normal case offline, not an edge case.
            $existing = CheckIn::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('client_scan_id', $clientScanId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $profile = EntranceEventProfile::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->first();

            $day = $profile?->dayCovering($at);
            $dayId = $day['day_id'] ?? null;

            // A calendar that exists but has no day for this moment is a
            // rejection: an admission is valid *on a day* (D-ADM-04), and a
            // scan at 3am between days has no day to be valid on. An event
            // with no calendar at all is not day-scoped, so it passes.
            if ($profile !== null && ($profile->days ?? []) !== [] && $dayId === null) {
                return $this->reject($brandId, $eventId, $admission->id, null, $clientScanId, $at, $checkpoint, $device, $direction, CheckInRejectionReason::OutsideEventDay, $actorUserId);
            }

            if (! $this->admitsOnDay($admittedDayIds, $dayId)) {
                return $this->reject($brandId, $eventId, $admission->id, $dayId, $clientScanId, $at, $checkpoint, $device, $direction, CheckInRejectionReason::NotValidOnThisDay, $actorUserId);
            }

            if (! $this->evaluateValidity->handle($admission, $at)) {
                return $this->reject($brandId, $eventId, $admission->id, $dayId, $clientScanId, $at, $checkpoint, $device, $direction, CheckInRejectionReason::AdmissionNotValid, $actorUserId);
            }

            // An event with no projected profile yet is not day-scoped and
            // carries no policy, so unlimited is the reading that keeps the
            // door open (ADR-0033).
            $policy = $profile === null ? ReentryPolicy::Unlimited : $profile->reentry_policy;

            if ($direction === 'in' && ! $policy->admitsAgain($this->acceptedDayIds($brandId, $admission->id), $dayId)) {
                return $this->reject($brandId, $eventId, $admission->id, $dayId, $clientScanId, $at, $checkpoint, $device, $direction, CheckInRejectionReason::ReentryNotPermitted, $actorUserId);
            }

            return $this->accept($admission, $brandId, $eventId, $dayId, $clientScanId, $at, $checkpoint, $device, $direction, $conditionsChecked, $actorUserId);
        });
    }

    /**
     * The single writer of a rejected check-in. A rejection is recorded, not
     * thrown: the Admission backlog asks for a full accepted/rejected audit
     * trail, and door staff need the reason preserved. A rejected row never
     * carries condition outcomes — a person who is not being let in is not
     * being asked for a document either.
     */
    public function reject(
        string $brandId,
        string $eventId,
        ?string $admissionId,
        ?string $dayId,
        string $clientScanId,
        CarbonInterface $at,
        ?Checkpoint $checkpoint,
        ?EntranceDevice $device,
        string $direction,
        CheckInRejectionReason $reason,
        ?string $actorUserId = null,
    ): CheckIn {
        $checkIn = $this->record(
            brandId: $brandId,
            admissionId: $admissionId,
            registrationId: null,
            dayId: $dayId,
            clientScanId: $clientScanId,
            scannedAt: $at,
            checkpoint: $checkpoint,
            device: $device,
            direction: $direction,
            result: CheckInResult::Rejected,
            reason: $reason,
            actorUserId: $actorUserId,
            conditionsChecked: null,
        );

        event(new CheckInRejected(
            checkInId: $checkIn->id,
            admissionId: $admissionId,
            eventId: $eventId,
            brandId: $brandId,
            checkpointId: $checkpoint?->id,
            reasonCode: $reason->value,
            scannedAt: $at->toIso8601String(),
        ));

        return $checkIn;
    }

    /**
     * `AdmissionTokenPayload::admitsOnDay()`, restated over a plain list so
     * the console can ask it without a token.
     *
     * @param  list<string>  $admittedDayIds
     */
    private function admitsOnDay(array $admittedDayIds, ?string $dayId): bool
    {
        if ($admittedDayIds === []) {
            return true;
        }

        return $dayId !== null && in_array($dayId, $admittedDayIds, true);
    }

    /**
     * Event-day ids of prior accepted entries. `null` entries (an event with
     * no calendar) are preserved rather than filtered — `once_per_day` on an
     * event without days then behaves as once-only, which is the safe reading.
     *
     * @return list<string|null>
     */
    private function acceptedDayIds(string $brandId, string $admissionId): array
    {
        $ids = CheckIn::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('admission_id', $admissionId)
            ->where('result', CheckInResult::Accepted)
            ->where('direction', 'in')
            ->pluck('event_day_id')
            ->all();

        return array_values(array_map(
            fn (mixed $id): ?string => $id === null ? null : (string) $id,
            $ids,
        ));
    }

    /**
     * @param  list<array{key: string, outcome: string}>  $conditionsChecked
     */
    private function accept(
        Admission $admission,
        string $brandId,
        string $eventId,
        ?string $dayId,
        string $clientScanId,
        CarbonInterface $scannedAt,
        ?Checkpoint $checkpoint,
        ?EntranceDevice $device,
        string $direction,
        array $conditionsChecked,
        ?string $actorUserId,
    ): CheckIn {
        $checkIn = $this->record(
            brandId: $brandId,
            admissionId: $admission->id,
            registrationId: $admission->registration_id,
            dayId: $dayId,
            clientScanId: $clientScanId,
            scannedAt: $scannedAt,
            checkpoint: $checkpoint,
            device: $device,
            direction: $direction,
            result: CheckInResult::Accepted,
            reason: null,
            actorUserId: $actorUserId,
            conditionsChecked: $conditionsChecked === [] ? null : $conditionsChecked,
        );

        // Feed the delta so every other device learns this admission is now
        // checked in — the cross-device duplicate check ADR-0033 buys with
        // Option C, bounded by the refresh interval rather than instant.
        if ($direction === 'in') {
            $entry = new EntranceDeltaEntry([
                'event_id' => $eventId,
                'admission_id' => $admission->id,
                'kind' => EntranceDeltaEntry::KIND_CHECKED_IN,
            ]);
            $entry->forceFill(['brand_id' => $brandId])->save();
        }

        event(new AdmissionCheckedIn(
            checkInId: $checkIn->id,
            admissionId: $admission->id,
            // ADR-0037's prerequisite — Commerce blocks refunds on this.
            registrationId: $admission->registration_id,
            eventId: $eventId,
            brandId: $brandId,
            eventDayId: $dayId,
            checkpointId: $checkpoint?->id,
            direction: $direction,
            scannedAt: $scannedAt->toIso8601String(),
            conditionsChecked: $conditionsChecked,
        ));

        return $checkIn;
    }

    /**
     * @param  list<array{key: string, outcome: string}>|null  $conditionsChecked
     */
    private function record(
        string $brandId,
        ?string $admissionId,
        ?string $registrationId,
        ?string $dayId,
        string $clientScanId,
        CarbonInterface $scannedAt,
        ?Checkpoint $checkpoint,
        ?EntranceDevice $device,
        string $direction,
        CheckInResult $result,
        ?CheckInRejectionReason $reason,
        ?string $actorUserId,
        ?array $conditionsChecked,
    ): CheckIn {
        $checkIn = new CheckIn([
            'admission_id' => $admissionId,
            'registration_id' => $registrationId,
            'checkpoint_id' => $checkpoint?->id,
            'device_id' => $device?->id,
            'event_day_id' => $dayId,
            'direction' => $direction,
            'result' => $result,
            'reason_code' => $reason?->value,
            'scanned_at' => $scannedAt,
            'synced_at' => now(),
            'client_scan_id' => $clientScanId,
            'actor_user_id' => $actorUserId,
            'conditions_checked' => $conditionsChecked,
        ]);
        // brand_id from the payload, not ambient context (ADR-0010).
        $checkIn->forceFill(['brand_id' => $brandId])->save();

        return $checkIn;
    }
}
