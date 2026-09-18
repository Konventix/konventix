<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingReleased;

/**
 * Keeps Admission's copy of which place each admission sits on (#292 §5,
 * ADR-0004): the ticket page and the PDF name the seat from here, never
 * from Resources' tables.
 *
 * The *confirmed* event, not the hold (Admission's SPEC already said so
 * for badges): a tentative booking must never reach a ticket. A booking
 * that names no admission — an operator reservation, the registration-
 * level pick path — is nobody's seat here and is ignored. One row per
 * admission: a re-pick confirms a new booking after releasing the old,
 * and the upsert on `admission_id` follows whichever arrives; the release
 * only deletes when the row still points at the booking being released,
 * so a release arriving after the re-pick's confirm cannot erase the new
 * seat.
 */
final class ProjectAdmissionSeat
{
    public function handleConfirmed(ResourceBookingConfirmed $event): void
    {
        if ($event->admissionId === null) {
            return;
        }

        $seat = AdmissionSeat::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('admission_id', $event->admissionId)
            ->first() ?? new AdmissionSeat(['admission_id' => $event->admissionId]);

        // brand_id from the payload, not ambient context (ADR-0010).
        $seat->forceFill([
            'brand_id' => $event->brandId,
            'booking_id' => $event->bookingId,
            'resource_type_id' => $event->resourceTypeId,
            'place_ref' => $event->ref,
            'place_label' => $event->ref ?? $event->label,
        ])->save();
    }

    public function handleReleased(ResourceBookingReleased $event): void
    {
        if ($event->admissionId === null) {
            return;
        }

        AdmissionSeat::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('admission_id', $event->admissionId)
            ->where('booking_id', $event->bookingId)
            ->delete();
    }
}
