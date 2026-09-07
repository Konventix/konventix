<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDeltaEntry;

/**
 * The counterpart of `IssueAdmissionsForRegistration` (ADR-0057 point 6):
 * the contractual act ended, so the access rights it produced end with it —
 * `AdmissionState::Revoked`, one `AdmissionRevoked` each, and a
 * `KIND_REVOKED` entry in the entrance delta feed — which is the producer
 * `AdmissionState::Revoked`, the SPEC's proposed `AdmissionRevoked` row and
 * `EntranceDeltaEntry::KIND_REVOKED` have all been waiting for.
 *
 * **The delta entry is not optional.** ADR-0033 buys offline door decisions
 * with a signed token the device can verify on its own, so a device only ever
 * learns *no* from the feed it polls; without a row here an offline scanner
 * keeps admitting a revoked person until its next full sync. The row holds an
 * opaque admission ID and nothing else, which is what makes a stolen scanner
 * leak nothing (`EntranceDeltaEntry`'s own docblock).
 *
 * **The one thing this Action never asks is whether anything was paid.** It
 * reacts to `RegistrationCancelled` alone; `PaymentReversed` has no listener
 * in this module at all, because a door that evaluates money is ADR-0005's
 * exact prohibition (and lessons-from-lancore #12). Between a reversal and
 * the repayment deadline the admission stays valid and the person gets in —
 * the door POS is where that money is collected.
 *
 * **An admission with an accepted check-in is kept.** Attendance happened;
 * revoking it afterwards would rewrite history, and the unpaid reversal is
 * Commerce's receivable (ADR-0057 point 4), not the door's. A *rejected*
 * scan is an audit row, not an attendance, so it does not protect anything.
 *
 * Takes scalars, not the Registration event object — the thin
 * `RevokeAdmissionsOnRegistrationCancelled` listener unpacks it, exactly as
 * on the issuing side, so nothing below this line knows the event exists.
 *
 * `RegistrationCancelled` carries no `brandId` (it never needed one), so the
 * lookup is by `registration_id` alone under `withoutBrandScope()` —
 * ADR-0010's named escape hatch, safe here because a registration ULID is
 * globally unique and every admission it produced belongs to that
 * registration's own brand. The event each revocation emits carries the
 * admission's own `brand_id`, read off the row rather than from ambient
 * context, so a replay outside any brand context still says the right thing.
 *
 * Idempotent and replayable: only `Issued`/`Assigned` admissions are
 * selected, so a second `RegistrationCancelled` for the same registration
 * finds nothing left to revoke.
 *
 * @see EvaluateAdmissionValidity — what a
 *   revoked admission then answers at the door.
 */
final class RevokeAdmissionsForRegistration
{
    /**
     * Why this admission stopped being valid, in Admission's own vocabulary:
     * the registration behind it ended. *Why the registration* ended is
     * Registration's reason code and stays there — the door has no use for
     * it, and carrying it here would smuggle payment reasons into this
     * module.
     */
    private const string REASON_REGISTRATION_CANCELLED = 'registration_cancelled';

    /**
     * @return list<Admission> the admissions actually revoked
     */
    public function handle(string $registrationId, ?string $revokedByUserId = null): array
    {
        return DB::transaction(function () use ($registrationId, $revokedByUserId): array {
            $admissions = Admission::withoutBrandScope()
                ->where('registration_id', $registrationId)
                ->whereIn('state', [AdmissionState::Issued, AdmissionState::Assigned])
                ->lockForUpdate()
                ->get();

            if ($admissions->isEmpty()) {
                return [];
            }

            $attended = $this->admissionIdsWithAcceptedCheckIn($admissions->modelKeys());

            $revoked = [];

            foreach ($admissions as $admission) {
                if (in_array($admission->id, $attended, true)) {
                    continue;
                }

                $admission->forceFill(['state' => AdmissionState::Revoked])->save();

                event(new AdmissionRevoked(
                    admissionId: $admission->id,
                    registrationId: $admission->registration_id,
                    eventId: $admission->event_id,
                    brandId: $admission->brand_id,
                    revokedByUserId: $revokedByUserId,
                    reasonCode: self::REASON_REGISTRATION_CANCELLED,
                    revokedAt: now()->toIso8601String(),
                ));

                // ADR-0033's negative decision: the signature alone cannot
                // say "no", so every door has to be told. `RecordScan` writes
                // the KIND_CHECKED_IN half of this feed.
                $entry = new EntranceDeltaEntry([
                    'event_id' => $admission->event_id,
                    'admission_id' => $admission->id,
                    'kind' => EntranceDeltaEntry::KIND_REVOKED,
                ]);

                // brand_id comes off the admission row rather than ambient
                // context, so a replay outside any brand context still lands
                // in the right brand's feed. It is deliberately not fillable.
                $entry->forceFill(['brand_id' => $admission->brand_id])->save();

                $revoked[] = $admission;
            }

            return $revoked;
        });
    }

    /**
     * @param  array<int, int|string>  $admissionIds
     * @return list<string>
     */
    private function admissionIdsWithAcceptedCheckIn(array $admissionIds): array
    {
        /** @var list<string> $ids */
        $ids = CheckIn::withoutBrandScope()
            ->whereIn('admission_id', $admissionIds)
            ->where('result', CheckInResult::Accepted)
            ->pluck('admission_id')
            ->all();

        return $ids;
    }
}
