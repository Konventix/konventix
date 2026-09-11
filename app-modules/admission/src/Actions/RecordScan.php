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
 * Evaluates one scanned token and records the outcome — the server-side
 * authority behind the entrance API (ADR-0033).
 *
 * The order of checks mirrors what an offline device can decide for itself,
 * deliberately: signature, then expiry, then event, then whether the token
 * itself is still current, then day-scoping, then admission validity, then
 * re-entry. Signature, expiry and event are decidable from the token alone;
 * everything after — nonce-supersession included — needs local state the
 * device gets from the delta feed (its own hash-per-admission cache, design
 * doc §5) and its synced calendar. Keeping the server in the same order
 * means both sides give the same answer and the reason codes match.
 *
 * A rejection is recorded, not thrown: the Admission backlog asks for a full
 * accepted/rejected audit trail, and door staff need the reason preserved.
 *
 * Idempotent per `clientScanId` — a device replaying its buffer after
 * reconnecting is the normal case offline, not an edge case.
 */
final class RecordScan
{
    public function __construct(
        private readonly VerifyAdmissionToken $verifyToken,
        private readonly EvaluateAdmissionValidity $evaluateValidity,
    ) {}

    public function handle(
        string $token,
        string $brandId,
        string $eventId,
        string $clientScanId,
        ?CarbonInterface $scannedAt = null,
        ?Checkpoint $checkpoint = null,
        ?EntranceDevice $device = null,
        string $direction = 'in',
    ): CheckIn {
        $scannedAt ??= now();

        return DB::transaction(function () use ($token, $brandId, $eventId, $clientScanId, $scannedAt, $checkpoint, $device, $direction): CheckIn {
            $existing = CheckIn::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('client_scan_id', $clientScanId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $verification = $this->verifyToken->handle($token, $brandId);
            $payload = $verification['payload'];

            if ($payload === null) {
                return $this->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::InvalidSignature);
            }

            if ($verification['expired']) {
                return $this->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::TokenExpired);
            }

            if ($payload->eventId !== $eventId) {
                return $this->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::WrongEvent);
            }

            $admission = Admission::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->whereKey($payload->admissionId)
                ->lockForUpdate()
                ->first();

            if ($admission === null) {
                return $this->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::UnknownAdmission);
            }

            if ($this->verifyToken->isSuperseded($payload, $admission)) {
                return $this->reject($brandId, $eventId, $admission->id, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::NonceSuperseded);
            }

            $profile = EntranceEventProfile::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->first();

            $day = $profile?->dayCovering($scannedAt);
            $dayId = $day['day_id'] ?? null;

            // A calendar that exists but has no day for this moment is a
            // rejection: an admission is valid *on a day* (D-ADM-04), and a
            // scan at 3am between days has no day to be valid on. An event
            // with no calendar at all is not day-scoped, so it passes.
            if ($profile !== null && ($profile->days ?? []) !== [] && $dayId === null) {
                return $this->reject($brandId, $eventId, $admission->id, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::OutsideEventDay);
            }

            if (! $payload->admitsOnDay($dayId)) {
                return $this->reject($brandId, $eventId, $admission->id, $dayId, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::NotValidOnThisDay);
            }

            if (! $this->evaluateValidity->handle($admission, $scannedAt)) {
                return $this->reject($brandId, $eventId, $admission->id, $dayId, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::AdmissionNotValid);
            }

            // An event with no projected profile yet is not day-scoped and
            // carries no policy, so unlimited is the reading that keeps the
            // door open (ADR-0033).
            $policy = $profile === null ? ReentryPolicy::Unlimited : $profile->reentry_policy;

            if ($direction === 'in' && ! $policy->admitsAgain($this->acceptedDayIds($brandId, $admission->id), $dayId)) {
                return $this->reject($brandId, $eventId, $admission->id, $dayId, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::ReentryNotPermitted);
            }

            return $this->accept($admission, $brandId, $eventId, $dayId, $clientScanId, $scannedAt, $checkpoint, $device, $direction);
        });
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
        ));

        return $checkIn;
    }

    private function reject(
        string $brandId,
        string $eventId,
        ?string $admissionId,
        ?string $dayId,
        string $clientScanId,
        CarbonInterface $scannedAt,
        ?Checkpoint $checkpoint,
        ?EntranceDevice $device,
        string $direction,
        CheckInRejectionReason $reason,
    ): CheckIn {
        $checkIn = $this->record(
            brandId: $brandId,
            admissionId: $admissionId,
            registrationId: null,
            dayId: $dayId,
            clientScanId: $clientScanId,
            scannedAt: $scannedAt,
            checkpoint: $checkpoint,
            device: $device,
            direction: $direction,
            result: CheckInResult::Rejected,
            reason: $reason,
        );

        event(new CheckInRejected(
            checkInId: $checkIn->id,
            admissionId: $admissionId,
            eventId: $eventId,
            brandId: $brandId,
            checkpointId: $checkpoint?->id,
            reasonCode: $reason->value,
            scannedAt: $scannedAt->toIso8601String(),
        ));

        return $checkIn;
    }

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
        ]);
        // brand_id from the payload, not ambient context (ADR-0010).
        $checkIn->forceFill(['brand_id' => $brandId])->save();

        return $checkIn;
    }
}
