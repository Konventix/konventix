<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * An orga reservation (design §7.3, Q7): hold plus confirm in one
 * transaction with holder `operator` and the acting user's id, over the
 * event window like every whole-event booking. No claim, no registration.
 *
 * Reserving an occupied place fails through the exclusion constraint —
 * `HoldResourceBooking` lets the database refuse and raises
 * `ResourceUnavailableException`; nothing here checks first (ADR-0020). A
 * blocked place may be reserved: blocking keeps registrants off it, which
 * is exactly when the orga wants it. A removed place may not.
 *
 * `Permission::BookOnBehalf` is checked by the console controller.
 */
final class ReservePlaceForOperator
{
    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
    ) {}

    public function handle(Resource $place, string $actorUserId): ResourceBooking
    {
        if ($place->trashed()) {
            throw ValidationException::withMessages(['placeId' => ['This place was removed from the plan.']]);
        }

        return DB::transaction(function () use ($place, $actorUserId): ResourceBooking {
            $type = ResourceType::withoutBrandScope()->whereKey($place->resource_type_id)->firstOrFail();
            $window = ResourceEventWindow::forEvent($place->brand_id, $type->event_id);

            if ($window === null) {
                throw ValidationException::withMessages(['placeId' => ['The event window is not projected yet; run event:replay-details.']]);
            }

            $booking = $this->hold->handle(
                resource: $place,
                holderType: ResourceBooking::HOLDER_OPERATOR,
                holderId: $actorUserId,
                startsAt: $window->starts_at,
                endsAt: $window->ends_at,
                quantity: 1,
            );

            return $this->confirm->handle($booking);
        });
    }
}
