<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Exceptions\ClaimLinkNotClaimableException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;

/**
 * Redeems a claim link: attaches the claiming account to the holder-less
 * admission behind it (ADR-0032).
 *
 * **The link is burned under a row lock**, the same shape as
 * `SubmitRegistration::guardVisibility()`'s invitation-token burn: the row is
 * selected `FOR UPDATE`, its claimability re-checked *after* the lock is
 * held, then marked claimed inside the same transaction. Checking before
 * locking would let two people who both opened the same forwarded link pass
 * the check simultaneously and race for one seat — which is precisely the
 * hazard ADR-0032 flags as unbuilt ("nothing prevents two people racing the
 * same link or code").
 *
 * Claiming sets a holder on an existing row; it never creates an admission.
 * The row, and therefore the admission's existence, predates the person who
 * ends up holding it (ADR-0032).
 */
final class ClaimAdmission
{
    public function handle(string $linkId, string $claimantUserId, ?string $claimantEmail = null): Admission
    {
        return DB::transaction(function () use ($linkId, $claimantUserId, $claimantEmail): Admission {
            /** @var AdmissionClaimLink|null $link */
            $link = AdmissionClaimLink::withoutBrandScope()
                ->whereKey($linkId)
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw ClaimLinkNotClaimableException::unknown();
            }

            // Re-checked with the lock held — before it, this is a race.
            if (! $link->isClaimable()) {
                throw ClaimLinkNotClaimableException::alreadySettled();
            }

            if (! $this->emailMatches($link->intended_email, $claimantEmail)) {
                throw ClaimLinkNotClaimableException::wrongRecipient();
            }

            /** @var Admission $admission */
            $admission = Admission::withoutBrandScope()
                ->where('brand_id', $link->brand_id)
                ->whereKey($link->admission_id)
                ->lockForUpdate()
                ->firstOrFail();

            // The admission itself may have been claimed by another route, or
            // revoked, since the link was minted.
            if ($admission->holder_user_id !== null) {
                throw ClaimLinkNotClaimableException::alreadyHeld();
            }

            if (! in_array($admission->state, [AdmissionState::Issued, AdmissionState::Assigned], true)) {
                throw ClaimLinkNotClaimableException::admissionNotClaimable();
            }

            $link->forceFill([
                'claimed_by_user_id' => $claimantUserId,
                'claimed_at' => now(),
            ])->save();

            $admission->forceFill([
                'holder_user_id' => $claimantUserId,
                'state' => AdmissionState::Assigned,
            ])->save();

            event(new AdmissionHolderAssigned(
                admissionId: $admission->id,
                registrationId: $admission->registration_id,
                eventId: $admission->event_id,
                brandId: $admission->brand_id,
                holderUserId: $claimantUserId,
                viaClaimLink: true,
                assignedAt: now()->toIso8601String(),
            ));

            return $admission->refresh();
        });
    }

    /**
     * An unbound link is claimable by whoever holds it — that is the point of
     * a shareable link an organizer forwards to a colleague. A bound one is
     * not, so forwarding it does not transfer the seat.
     */
    private function emailMatches(?string $intended, ?string $claimant): bool
    {
        if ($intended === null) {
            return true;
        }

        return $claimant !== null && mb_strtolower($intended) === mb_strtolower($claimant);
    }
}
