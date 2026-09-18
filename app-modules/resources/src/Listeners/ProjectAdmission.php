<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Resources\Models\ResourceAdmission;

/**
 * Keeps Resources' copy of every admission (#292 §6.1, ADR-0004): the
 * per-admission seat picker needs to know who holds a ticket and who
 * bought it, and this module may not read Admission's table.
 *
 * Every handler is an upsert on the admission's own id, so creation,
 * holder changes and the backfill (`AdmissionOwnershipAnnounced`) converge
 * on one row in any order — a holder change arriving before the issue
 * (a replay out of sequence) creates the row with what it knows. Brand
 * from the payload, never ambient context (ADR-0010). Ungated, like every
 * read-model projector: a brand that switches Resources on later must
 * find its admissions already here.
 */
final class ProjectAdmission
{
    public function handleIssued(AdmissionIssued $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'registration_id' => $event->registrationId,
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
            'buyer_user_id' => $event->buyerUserId,
            'state' => $event->holderUserId === null ? ResourceAdmission::STATE_ISSUED : ResourceAdmission::STATE_ASSIGNED,
        ]);
    }

    public function handleHolderAssigned(AdmissionHolderAssigned $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'registration_id' => $event->registrationId,
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
            'state' => ResourceAdmission::STATE_ASSIGNED,
        ]);
    }

    public function handleHolderUnassigned(AdmissionHolderUnassigned $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'registration_id' => $event->registrationId,
            'event_id' => $event->eventId,
            'holder_user_id' => null,
            'state' => ResourceAdmission::STATE_ISSUED,
        ]);
    }

    public function handleRevoked(AdmissionRevoked $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'registration_id' => $event->registrationId,
            'event_id' => $event->eventId,
            'state' => ResourceAdmission::STATE_REVOKED,
        ]);
    }

    public function handleOwnershipAnnounced(AdmissionOwnershipAnnounced $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'registration_id' => $event->registrationId,
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
            'buyer_user_id' => $event->buyerUserId,
            // Admission's `expired` has no seat meaning; anything that is
            // not revoked still stands here.
            'state' => $event->state === ResourceAdmission::STATE_REVOKED
                ? ResourceAdmission::STATE_REVOKED
                : ($event->holderUserId === null ? ResourceAdmission::STATE_ISSUED : ResourceAdmission::STATE_ASSIGNED),
        ]);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function upsert(string $admissionId, string $brandId, array $values): void
    {
        $row = ResourceAdmission::withoutBrandScope()->find($admissionId)
            ?? new ResourceAdmission(['id' => $admissionId]);

        $row->forceFill(['brand_id' => $brandId, ...$values])->save();
    }
}
