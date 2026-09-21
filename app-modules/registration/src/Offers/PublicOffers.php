<?php

declare(strict_types=1);

namespace Konventix\Registration\Offers;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Registration\Data\PublicOfferData;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\RegistrationType;

/**
 * The one answer to "which of an event's types may a visitor be shown right
 * now" (bugfix round 2026-09-21, Task 7). The `event.landing` slot and the
 * `tickets-for-event` static-page block both render the same offers, and
 * two hand-copied queries would drift the day one of them learns a new
 * rule — the sales window (#218) already had to be added in two places.
 *
 * The rule: only `Public` types — `Link` and `InviteOnly` are reachable with
 * a token and must not be advertised to someone who does not hold one
 * (D-COM-02) — whose sale is not over (`RegistrationType::scopeSaleNotOver()`,
 * kept beside `isOnSale()`). A sale that has not started yet stays in the
 * list, announced rather than linked, so the page can carry the "sale
 * starts" date a visitor came for. Ordered by name.
 *
 * Whether the *event* may be shown at all is not decided here: the landing
 * page is addressed by the event and Event has already decided, while a
 * static page or `/shop` has no event in its address and asks
 * `EventSummary::scopeListedPublicly()` first.
 */
final class PublicOffers
{
    /**
     * @return Builder<RegistrationType>
     */
    public static function query(): Builder
    {
        return RegistrationType::query()
            ->where('visibility', RegistrationVisibility::Public)
            ->saleNotOver()
            ->orderBy('name');
    }

    /**
     * One event's offers, as the landing page and the block show them.
     *
     * @return list<PublicOfferData>
     */
    public static function forEvent(string $eventId): array
    {
        return array_values(self::query()
            ->where('event_id', $eventId)
            ->get()
            ->map(fn (RegistrationType $type): PublicOfferData => PublicOfferData::ofType($type))
            ->all());
    }
}
