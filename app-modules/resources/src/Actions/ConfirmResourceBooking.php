<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
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
 *
 * Its event is dispatched after the outermost commit, never inside the
 * transaction (design §6.1 step 7). A caller that wraps this call in
 * `BrandContext::withBrand()` — `ReservePlaceForOperator` holds and confirms
 * in one transaction — must not assume that scope is still open when the
 * deferred dispatch runs: whatever brand is ambient when the event is built
 * is captured and restored for the deferred callback alone, exactly as
 * `ReleaseResourceBooking` does.
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

            $event = new ResourceBookingConfirmed(
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
                ref: $resource->ref,
            );

            // Deferred to the outermost commit (design §6.1 step 7): SavePlan
            // and the seat assignment release inside their own transaction,
            // and a listener must never see a release the caller then rolls
            // back. Without an open transaction the callback runs at once.
            // Whatever brand is ambient right now is captured and restored
            // around the deferred dispatch, because by the time it runs a
            // caller's own withBrand() block has already put the previous
            // brand back (see ReleaseResourceBooking).
            $brand = app(BrandContext::class)->current();

            DB::afterCommit(static function () use ($event, $brand): void {
                if ($brand === null) {
                    event($event);

                    return;
                }

                app(BrandContext::class)->withBrand($brand, static function () use ($event): void {
                    event($event);
                });
            });

            return $booking->refresh();
        });
    }
}
