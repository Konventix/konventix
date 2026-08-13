<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Models\Admission;

/**
 * Issues the crew badge behind an accepted shift assignment (D-STF-01).
 *
 * A crew admission has **no registration**: the person is rostered, not
 * registered, and there is no commercial act behind their badge. That is why
 * `admissions.registration_id` became nullable — the alternative was inventing
 * a synthetic registration to satisfy a column, which would have put a fake
 * row in Registration's tables to please Admission's schema, exactly the
 * coupling ADR-0005 exists to prevent.
 *
 * Unlike a registration's admissions, this one is **assigned from birth**: the
 * whole point is that a known person is working, so there is no holder-less
 * placeholder phase and no claim link.
 *
 * Idempotent on `assignment_id`, the crew counterpart of `amendment_id`:
 * confirming an assignment twice issues one badge.
 *
 * Takes scalars, never Staffing's classes — the thin listener unpacks the
 * event (ADR-0004).
 */
final class IssueCrewAdmission
{
    public function handle(
        string $assignmentId,
        string $eventId,
        string $brandId,
        string $userId,
        string $role,
    ): Admission {
        return DB::transaction(function () use ($assignmentId, $eventId, $brandId, $userId, $role): Admission {
            $existing = Admission::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('assignment_id', $assignmentId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $admission = new Admission([
                'registration_id' => null,
                'assignment_id' => $assignmentId,
                'event_id' => $eventId,
                'holder_user_id' => $userId,
                'role' => $role,
                'valid_from' => null,
                'valid_until' => null,
                // Assigned, not Issued: a crew badge belongs to a named person
                // from the moment it exists.
                'state' => AdmissionState::Assigned,
            ]);
            // brand_id from the payload, not ambient context (ADR-0010).
            $admission->forceFill(['brand_id' => $brandId])->save();

            event(new AdmissionIssued(
                admissionId: $admission->id,
                registrationId: null,
                eventId: $eventId,
                brandId: $brandId,
                role: $role,
                holderUserId: $userId,
                issuedAt: $admission->created_at->toIso8601String(),
            ));

            return $admission;
        });
    }
}
