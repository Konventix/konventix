<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Models\ResourceBooking;
use RuntimeException;

/**
 * Turns a hold into a firm booking — the point at which other modules may
 * act on it (SPEC.md: a hold "must never reach a badge").
 *
 * An expired hold is refused rather than quietly revived: its interval may
 * already have been taken by someone else, and confirming it would either
 * violate the exclusion constraint or silently double-book in quantity mode.
 */
final class ConfirmResourceBooking
{
    public function handle(ResourceBooking $booking): ResourceBooking
    {
        return DB::transaction(function () use ($booking): ResourceBooking {
            if ($booking->state === BookingState::Confirmed) {
                return $booking;
            }

            if ($booking->state !== BookingState::Held) {
                throw new RuntimeException(sprintf(
                    'Booking [%s] is %s and cannot be confirmed.',
                    $booking->id,
                    $booking->state->value,
                ));
            }

            if ($booking->isExpired()) {
                throw new RuntimeException(sprintf(
                    'Booking [%s] expired at %s; its interval may already be taken.',
                    $booking->id,
                    $booking->hold_expires_at?->toIso8601String() ?? 'unknown',
                ));
            }

            $booking->forceFill([
                'state' => BookingState::Confirmed,
                'hold_expires_at' => null,
            ])->save();

            $resource = $booking->resourceUnscoped();
            $type = $resource->resourceTypeUnscoped();

            event(new ResourceBookingConfirmed(
                bookingId: $booking->id,
                resourceId: $booking->resource_id,
                brandId: $booking->brand_id,
                holderType: $booking->holder_type,
                holderId: $booking->holder_id,
                registrationId: $booking->registration_id,
                quantity: $booking->quantity,
                startsAt: $booking->starts_at->toIso8601String(),
                endsAt: $booking->ends_at->toIso8601String(),
                confirmedAt: now()->toIso8601String(),
                eventId: $type->event_id,
                resourceTypeId: $type->id,
                claimId: $booking->claim_id,
                label: $booking->label ?? $resource->label,
                ref: null,
            ));

            return $booking->refresh();
        });
    }
}
