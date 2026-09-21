<?php

declare(strict_types=1);

namespace Konventix\Registration\Offers;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Platform\Data\EndPriceData;
use Konventix\Platform\Pricing\EndPriceCalculator;
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
        /** @var list<RegistrationType> $types */
        $types = array_values(self::query()->where('event_id', $eventId)->get()->all());

        $endPrices = self::endPricesFor($types);

        return array_map(
            fn (RegistrationType $type, ?EndPriceData $endPrice): PublicOfferData => PublicOfferData::ofType(
                $type,
                endPrice: $endPrice,
            ),
            $types,
            $endPrices,
        );
    }

    /**
     * What these offers actually cost, one entry per type and in the same
     * order — `null` for a free type and for anything that could not be
     * priced (#234, D-COM-20).
     *
     * ADR-0017 across a module boundary: this module declares a net amount
     * and a tax *class* and knows neither the rate nor the operator's §19
     * election; Commerce computes the end price behind Platform's
     * `Pricing\EndPriceCalculator`, and nothing here imports
     * `Commerce\Tax\*` (ADR-0004, pinned by `ArchitectureTest`).
     *
     * Resolved once for the whole list rather than per offer: a landing
     * page with a dozen types would otherwise ask the operator's settings a
     * dozen times for an answer that cannot differ between two offers of
     * one brand. Shared with the `shop.index` slot closure, which builds
     * its list from a different query.
     *
     * `app()->bound()` first, and the container read happens *here* rather
     * than in a constructor: this is called from a slot closure the
     * `SlotRegistry` singleton outlives the request with (ADR-0003,
     * `.ai/rules/providers.md`), and an injected calculator would be pinned
     * into an Octane worker along with the brand it first saw. Nothing
     * bound at all is the fail-soft case the contract describes — the
     * caller then renders net, which is what these pages did before #234.
     *
     * @param  list<RegistrationType>  $types
     * @return list<EndPriceData|null>
     */
    public static function endPricesFor(array $types): array
    {
        /** @var list<EndPriceData|null> $endPrices */
        $endPrices = array_fill(0, count($types), null);

        if ($types === [] || ! app()->bound(EndPriceCalculator::class)) {
            return $endPrices;
        }

        /** @var list<int> $positions */
        $positions = [];
        /** @var list<array{unitAmountNet: string, taxClass: string}> $lines */
        $lines = [];

        foreach ($types as $index => $type) {
            $declaration = $type->price_declaration;

            // A free type has no price at all, never a zero one (ADR-0017)
            // — it stays null and the card says "Free".
            if ($declaration === null) {
                continue;
            }

            $positions[] = $index;
            $lines[] = [
                'unitAmountNet' => $declaration->unitAmountNet,
                'taxClass' => $declaration->taxClass->value,
            ];
        }

        if ($lines === []) {
            return $endPrices;
        }

        // Every type in one list belongs to the brand the request resolved
        // (`BelongsToBrand`'s global scope), so the first one names it.
        $answers = app(EndPriceCalculator::class)->endPricesFor($types[0]->brand_id, $lines);

        foreach ($positions as $position => $index) {
            $endPrices[$index] = $answers[$position] ?? null;
        }

        return array_values($endPrices);
    }
}
