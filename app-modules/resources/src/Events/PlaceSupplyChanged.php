<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A category's bookable supply moved for a reason that is not a sale
 * (design §12b, #282): a place was blocked or unblocked, or reserved for
 * or released from the organisation. `ResourceBookingConfirmed`/`Released`
 * already cover a registrant's own seat pick — this event exists so
 * Registration can treat every *other* way a place stops or starts being
 * sellable the same way it treats a sale, without double-counting a pick
 * it already counted at submission.
 *
 * Publishing and unpublishing a plan deliberately do **not** fire this
 * event: `PublishPlan`/`UnpublishPlan` each snapshot the plan's current
 * unblocked count at one instant, and the two snapshots need not match, so
 * treating either as a supply move can strand a unit permanently or —
 * worse, on the ordinary "draft, size the quota, sell, then publish"
 * sequence — zero out sales already recorded the moment the plan opens.
 * See `UnpublishPlan`'s docblock.
 *
 * `direction` is `consumed` (the category has `places` fewer sellable
 * units — blocked, reserved) or `released` (`places` more — unblocked,
 * released); `reason` is one of `blocked`, `unblocked`, `reserved`,
 * `released`, carried only for observability, never branched on
 * downstream. `changeId` is this transition's own identity (a fresh ULID
 * per emission, unrelated to any row here) so a consumer can recognise a
 * replayed delivery and not apply it twice.
 *
 * Payload is scalars/ULIDs only (ADR-0004): the emitting Actions —
 * `SavePlan`'s projection, `ReservePlaceForOperator`,
 * `ReleaseOperatorReservation` — are the only places that change these
 * states, so this event fires from there and nowhere else.
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
