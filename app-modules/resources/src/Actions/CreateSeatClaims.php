<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Events\SeatClaimCreated;
use Konventix\Resources\Models\ResourceClaim;

/**
 * Turns a confirmed registration's `seating` grant into a `resource_claims`
 * row (seating design §7.2) — the Resources-owned snapshot of the
 * entitlement, `unplaced` until the registrant (or an operator) picks.
 *
 * Takes scalars, never Registration's classes: the thin listener unpacks the
 * event, the shape `FulfilResourceClaims` uses. `'seating'` is a literal,
 * the string is the contract (ADR-0004).
 *
 * Idempotent per confirmation occasion: `(registration_id, amendment_id)` is
 * unique on the table, so a replayed confirmation or amendment finds its
 * claim and adds none. The same key is why a payload with two seating grants
 * yields one claim — Registration's guard refuses such a type, and this
 * Action does not second-guess an older payload by inventing a second key.
 *
 * `brand_id` comes from the payload, not ambient context (ADR-0010): the
 * confirmation may arrive from a webhook or the scheduler with no brand.
 *
 * @phpstan-type Grant array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids?: list<string>|null, seating_mode?: string|null}
 */
final class CreateSeatClaims
{
    private const string SEATING_GRANT_KIND = 'seating';

    /**
     * @param  list<Grant>  $grants
     * @return list<ResourceClaim>
     */
    public function handle(
        string $registrationId,
        string $eventId,
        string $brandId,
        ?string $registrantUserId,
        array $grants,
        ?string $amendmentId = null,
    ): array {
        return DB::transaction(function () use ($registrationId, $eventId, $brandId, $registrantUserId, $grants, $amendmentId): array {
            $created = [];

            foreach ($grants as $grant) {
                if ($grant['kind'] !== self::SEATING_GRANT_KIND) {
                    continue;
                }

                $resourceTypeIds = $grant['resource_type_ids'] ?? null;
                $seatingMode = $grant['seating_mode'] ?? null;

                if ($resourceTypeIds === null || $resourceTypeIds === [] || $seatingMode === null) {
                    // A payload older than the seating contract, or one the
                    // Registration guard should have refused. Nothing to
                    // pick from — skip rather than fail the confirmation.
                    continue;
                }

                $exists = ResourceClaim::withoutBrandScope()
                    ->where('brand_id', $brandId)
                    ->where('registration_id', $registrationId)
                    ->where('amendment_id', $amendmentId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $claim = new ResourceClaim([
                    'event_id' => $eventId,
                    'registration_id' => $registrationId,
                    'registrant_user_id' => $registrantUserId,
                    'amendment_id' => $amendmentId,
                    'resource_type_ids' => $resourceTypeIds,
                    'seating_mode' => SeatingMode::from($seatingMode),
                    'quantity' => $grant['quantity'],
                    'state' => ClaimState::Unplaced,
                ]);
                $claim->forceFill(['brand_id' => $brandId])->save();

                event(new SeatClaimCreated(
                    claimId: $claim->id,
                    registrationId: $registrationId,
                    eventId: $eventId,
                    brandId: $brandId,
                ));

                $created[] = $claim;
            }

            return $created;
        });
    }
}
