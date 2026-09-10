<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A category's bookable supply moved for a reason that is not a sale
 * (design §12b, #282): a place was blocked or unblocked, reserved for or
 * released from the organisation, or a whole plan was published or
 * unpublished. `ResourceBookingConfirmed`/`Released` already cover a
 * registrant's own seat pick — this event exists so Registration can treat
 * every *other* way a place stops or starts being sellable the same way it
 * treats a sale, without double-counting a pick it already counted at
 * submission.
 *
 * `direction` is `consumed` (the category has `places` fewer sellable
 * units — blocked, reserved, unpublished) or `released` (`places` more —
 * unblocked, released, published); `reason` is one of `blocked`,
 * `unblocked`, `reserved`, `released`, `published`, `unpublished`, carried
 * only for observability, never branched on downstream. `changeId` is this
 * transition's own identity (a fresh ULID per emission, unrelated to any
 * row here) so a consumer can recognise a replayed delivery and not apply
 * it twice.
 *
 * Payload is scalars/ULIDs only (ADR-0004): the emitting Actions —
 * `SavePlan`'s projection, `PublishPlan`, `UnpublishPlan`,
 * `ReservePlaceForOperator`, `ReleaseOperatorReservation` — are the only
 * places that change these states, so this event fires from there and
 * nowhere else.
 */
final readonly class PlaceSupplyChanged
{
    public function __construct(
        public string $changeId,
        public string $brandId,
        public string $eventId,
        public string $resourceTypeId,
        public string $reason,
        public string $direction,
        public int $places,
    ) {}
}
