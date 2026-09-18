<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Actions\Concerns\GuardsHolderActor;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Exceptions\HolderChangeRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;

/**
 * Takes an admission back from its holder (#292 §4.1, #73 "Umschreibung"):
 * the buyer, by ownership, or an organizer with `Permission::ReassignHolder`.
 *
 * Refused once the door has accepted the admission: a person who is
 * already inside cannot be un-named after the fact, and a reassignment
 * from that point on is a new admission's job. The seat is deliberately
 * left alone — Resources binds a place to the admission, not to the
 * person, and whoever is named next inherits it (§6.5). Reassignment is
 * this followed by `AssignHolder` or an invite: two events, never a silent
 * swap, so the audit reads what happened.
 */
final class UnassignHolder
{
    use GuardsHolderActor;

    public const string REASON_MANAGER = 'manager_unassigned';

    public const string REASON_DESK = 'desk_unassigned';

    public function handle(Admission $admission, string $actorType, ?string $actorUserId, string $reasonCode): Admission
    {
        $this->guardActor($admission, $actorType, $actorUserId, Permission::ReassignHolder);

        return DB::transaction(function () use ($admission, $actorUserId, $reasonCode): Admission {
            /** @var Admission $locked */
            $locked = Admission::withoutBrandScope()
                ->whereKey($admission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->holder_user_id === null) {
                throw HolderChangeRefusedException::notHeld();
            }

            if ($locked->state === AdmissionState::Revoked) {
                throw HolderChangeRefusedException::notInAssignableState();
            }

            $checkedIn = CheckIn::withoutBrandScope()
                ->where('admission_id', $locked->id)
                ->where('result', CheckInResult::Accepted)
                ->exists();

            if ($checkedIn) {
                throw HolderChangeRefusedException::checkedIn();
            }

            $previousHolder = $locked->holder_user_id;

            $locked->forceFill([
                'holder_user_id' => null,
                'state' => AdmissionState::Issued,
            ])->save();

            event(new AdmissionHolderUnassigned(
                admissionId: $locked->id,
                registrationId: $locked->registration_id,
                eventId: $locked->event_id,
                brandId: $locked->brand_id,
                previousHolderUserId: $previousHolder,
                unassignedByUserId: $actorUserId,
                reasonCode: $reasonCode,
                unassignedAt: now()->toIso8601String(),
            ));

            return $locked->refresh();
        });
    }
}
