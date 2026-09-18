<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Actions\Concerns\GuardsHolderActor;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Exceptions\HolderChangeRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;

/**
 * Names the holder of a holder-less admission without a claim link (#292
 * §4.1): the buyer saying "this one is mine", or an organizer naming
 * somebody at the desk (#16 point 3, `Permission::AssignHolder`).
 *
 * The guard is `ClaimAdmission`'s — no holder yet, state `issued` or
 * `assigned` — re-checked under the row lock, so a claim link redeemed in
 * the same instant loses cleanly to whichever transaction commits first.
 * A live claim link is revoked in the same transaction: a named admission
 * has nothing left to claim, and a link still circulating would otherwise
 * 410 confusingly rather than simply not exist.
 *
 * The manager's controller only ever passes the manager's own id as the
 * holder (§12 point 4): naming a third person without their consent is
 * what the invite exists for. The Action itself does not enforce that —
 * an operator legitimately names other people — so the restriction lives
 * where the manager's request is translated, not here.
 */
final class AssignHolder
{
    use GuardsHolderActor;

    public function handle(Admission $admission, string $holderUserId, string $actorType, ?string $actorUserId): Admission
    {
        $this->guardActor($admission, $actorType, $actorUserId, Permission::AssignHolder);

        return DB::transaction(function () use ($admission, $holderUserId): Admission {
            /** @var Admission $locked */
            $locked = Admission::withoutBrandScope()
                ->whereKey($admission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->holder_user_id !== null) {
                throw HolderChangeRefusedException::alreadyHeld();
            }

            if (! in_array($locked->state, [AdmissionState::Issued, AdmissionState::Assigned], true)) {
                throw HolderChangeRefusedException::notInAssignableState();
            }

            AdmissionClaimLink::withoutBrandScope()
                ->where('brand_id', $locked->brand_id)
                ->where('admission_id', $locked->id)
                ->whereNull('claimed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $locked->forceFill([
                'holder_user_id' => $holderUserId,
                'state' => AdmissionState::Assigned,
            ])->save();

            event(new AdmissionHolderAssigned(
                admissionId: $locked->id,
                registrationId: $locked->registration_id,
                eventId: $locked->event_id,
                brandId: $locked->brand_id,
                holderUserId: $holderUserId,
                viaClaimLink: false,
                assignedAt: now()->toIso8601String(),
            ));

            return $locked->refresh();
        });
    }
}
