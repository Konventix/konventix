<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Data\PlanAvailabilityData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\PlaceState;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;

/**
 * The picker's availability map (design §7.4): one state per place of the
 * claim's event, computed from the projection and the occupying bookings
 * and never from the plan document (design §6.2 — the projection carries
 * no booking state, the document carries no availability).
 *
 * Precedence, highest first: `mine` (a booking of this registration —
 * the registrant must always find their own place, whatever else is true
 * of it), then `held`/`booked` for anybody else's occupying booking, then
 * `unavailable` for a blocked place or one on an unpublished plan, then
 * `free`. Two reads, no join: the place list is short (≤ 2,000 elements
 * per plan, design §12) and the second read is bounded by the first.
 *
 * Brand-scoped through `BelongsToBrand`: this runs on the brand host under
 * the registrant's session, never in the console.
 */
final class ComputePlanAvailability
{
    public function handle(ResourceClaim $claim): PlanAvailabilityData
    {
        $planIds = Plan::query()
            ->where('event_id', $claim->event_id)
            ->pluck('id');

        $places = Resource::query()
            ->whereIn('plan_id', $planIds)
            ->get(['id', 'is_published', 'blocked']);

        /** @var array<string, PlaceState> $states */
        $states = [];

        foreach ($places as $place) {
            $states[$place->getKey()] = ($place->is_published && ! $place->blocked)
                ? PlaceState::Free
                : PlaceState::Unavailable;
        }

        ResourceBooking::query()
            ->whereIn('resource_id', $places->modelKeys())
            ->whereIn('state', BookingState::occupyingValues())
            ->get(['resource_id', 'registration_id', 'state'])
            ->each(function (ResourceBooking $booking) use (&$states, $claim): void {
                if ($booking->registration_id === $claim->registration_id) {
                    $states[$booking->resource_id] = PlaceState::Mine;

                    return;
                }

                if (($states[$booking->resource_id] ?? null) === PlaceState::Mine) {
                    return;
                }

                $states[$booking->resource_id] = $booking->state === BookingState::Held
                    ? PlaceState::Held
                    : PlaceState::Booked;
            });

        return new PlanAvailabilityData(
            eventId: $claim->event_id,
            generatedAt: now()->toIso8601String(),
            places: $states,
        );
    }
}
