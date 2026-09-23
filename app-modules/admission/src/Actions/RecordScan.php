<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;

/**
 * Evaluates one scanned token and records the outcome — the server-side
 * authority behind the entrance API (ADR-0033).
 *
 * This class owns the token half: signature, then expiry, then event, then
 * whether the token itself is still current. Those are decidable from the
 * token alone (nonce-supersession needs the device's hash-per-admission
 * cache, design doc §5). Everything after — day-scoping, admission validity,
 * re-entry — is the shared core, `RecordCheckIn`, which the console reaches
 * without a token (attendee-list design §3.1, D-ATT-08). The token's day
 * scope is handed to the core as a plain list, so the order of checks is the
 * one an offline device follows, and both sides give the same reason code.
 *
 * A rejection is recorded, not thrown: the Admission backlog asks for a full
 * accepted/rejected audit trail, and door staff need the reason preserved.
 * `RecordCheckIn::reject()` writes every rejected row, the five token-level
 * ones below included.
 *
 * Idempotent per `clientScanId` — a device replaying its buffer after
 * reconnecting is the normal case offline, not an edge case.
 */
final class RecordScan
{
    public function __construct(
        private readonly VerifyAdmissionToken $verifyToken,
        private readonly RecordCheckIn $recordCheckIn,
    ) {}

    /**
     * `$conditionsChecked` is what the door decided about this holder's
     * entry conditions (ADR-0066 §6.4): `[{key, outcome}]`, empty for every
     * scan that checks nothing. It rides on the accepted scan and on
     * `AdmissionCheckedIn`; a rejected scan carries none, because a person
     * who is not being let in is not being asked for a document either.
     *
     * It changes nothing about the decision. The door decides access, the
     * outcome records a fact, and a refusal never revokes the admission
     * (T-8, ADR-0005) — which is also why it is read after every other
     * check rather than before any of them.
     *
     * @param  list<array{key: string, outcome: string}>  $conditionsChecked
     */
    public function handle(
        string $token,
        string $brandId,
        string $eventId,
        string $clientScanId,
        ?CarbonInterface $scannedAt = null,
        ?Checkpoint $checkpoint = null,
        ?EntranceDevice $device = null,
        string $direction = 'in',
        array $conditionsChecked = [],
    ): CheckIn {
        $scannedAt ??= now();

        return DB::transaction(function () use ($token, $brandId, $eventId, $clientScanId, $scannedAt, $checkpoint, $device, $direction, $conditionsChecked): CheckIn {
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
                return $this->recordCheckIn->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::InvalidSignature);
            }

            if ($verification['expired']) {
                return $this->recordCheckIn->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::TokenExpired);
            }

            if ($payload->eventId !== $eventId) {
                return $this->recordCheckIn->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::WrongEvent);
            }

            $admission = Admission::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->whereKey($payload->admissionId)
                ->lockForUpdate()
                ->first();

            if ($admission === null) {
                return $this->recordCheckIn->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::UnknownAdmission);
            }

            if ($this->verifyToken->isSuperseded($payload, $admission)) {
                return $this->recordCheckIn->reject($brandId, $eventId, $admission->id, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::NonceSuperseded);
            }

            // The admission is resolved and locked; every admission-level
            // check from here on is the shared core's, with the token's day
            // scope as the one thing only the token knows.
            return $this->recordCheckIn->handle(
                admission: $admission,
                eventId: $eventId,
                clientScanId: $clientScanId,
                at: $scannedAt,
                checkpoint: $checkpoint,
                device: $device,
                direction: $direction,
                conditionsChecked: $conditionsChecked,
                admittedDayIds: $payload->dayIds,
            );
        });
    }
}
