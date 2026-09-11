<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\GrantKind;

/**
 * The cross-grant rules of a seating grant (seating design §7.1), shared by
 * `GuardsTypeConfiguration` (type create/update) and `AmendRegistration`
 * (a delta that adds a seating grant).
 *
 * - A seating grant names at least one category and a mode; without either
 *   the picker has nothing to offer and Resources could not build a claim.
 * - One seating grant per grant list. Resources keys its claims on
 *   `(registration_id, amendment_id)` — one per confirmation occasion — so
 *   a second grant would be silently dropped. Several categories are one
 *   grant with several `resourceTypeIds`.
 * - A category is either claimed first-fit (`resource_claim`) or offered for
 *   picking (`seating`), never both on the same type: the first-fit booking
 *   would take the very places the registrant is then asked to choose from.
 */
trait GuardsSeatingGrants
{
    /**
     * @param  list<GrantData>  $grants
     */
    private function guardSeatingGrants(array $grants): void
    {
        $seating = array_values(array_filter(
            $grants,
            fn (GrantData $grant): bool => $grant->kind === GrantKind::Seating,
        ));

        if ($seating === []) {
            return;
        }

        if (count($seating) > 1) {
            throw ValidationException::withMessages([
                'grants' => __('A type carries at most one seating grant; list every category it may choose from in that grant.'),
            ]);
        }

        $grant = $seating[0];

        if ($grant->resourceTypeIds === null || $grant->resourceTypeIds === [] || $grant->seatingMode === null) {
            throw ValidationException::withMessages([
                'grants' => __('A seating grant needs at least one category and a seating mode.'),
            ]);
        }

        foreach ($grants as $other) {
            if (
                $other->kind === GrantKind::ResourceClaim
                && $other->resourceTypeId !== null
                && in_array($other->resourceTypeId, $grant->resourceTypeIds, true)
            ) {
                throw ValidationException::withMessages([
                    'grants' => __('Category :id is both claimed first-fit and offered for seating; a category is one or the other.', [
                        'id' => $other->resourceTypeId,
                    ]),
                ]);
            }
        }
    }
}
