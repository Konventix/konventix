<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Events\SeatClaimReleased;
use Konventix\Resources\Models\ResourceClaim;

/**
 * Ends every live seat claim of a registration (seating design §7.2, §7.5):
 * cancellation and rejection take the entitlement away, so "Meine
 * Registrierung" must stop offering the picker. The claim's bookings are
 * released by the same listener through S0's
 * `ReleaseBookingsForRegistration` (state `cancelled`, reason
 * `registration_cancelled`/`registration_rejected`); this Action only moves
 * the claim rows and says so.
 *
 * Idempotent — an already-released claim is skipped and emits nothing,
 * because a cancellation arriving twice is normal. Ungated on purpose, like
 * the booking release beside it (ADR-0060's explicit ADR-0003 exception):
 * giving something back is safe for a brand that switched the module off.
 *
 * `RegistrationCancelled` carries no brand id, so the lookup is by
 * registration id `withoutBrandScope()` — a registration ULID is unique
 * across brands.
 *
 * `SeatClaimReleased` is dispatched after the outermost commit, never
 * inside the transaction, for the same reason `CreateSeatClaims` defers
 * `SeatClaimCreated`: this Action's own transaction is a savepoint nested
 * inside whatever transaction wraps `RegistrationCancelled`/`Rejected`.
 * Whatever brand is ambient right now is captured and restored around the
 * deferred dispatch, exactly as `HoldResourceBooking` does.
 */
final class ReleaseSeatClaims
{
    public const string REASON_CANCELLED = 'registration_cancelled';

    public const string REASON_REJECTED = 'registration_rejected';

    /**
     * @return list<ResourceClaim>
     */
    public function handle(string $registrationId, string $reason): array
    {
        return DB::transaction(function () use ($registrationId, $reason): array {
            $claims = ResourceClaim::withoutBrandScope()
                ->where('registration_id', $registrationId)
                ->whereIn('state', [ClaimState::Unplaced->value, ClaimState::Placed->value])
                ->lockForUpdate()
                ->get();

            $released = [];

            foreach ($claims as $claim) {
                $claim->forceFill(['state' => ClaimState::Released])->save();

                $event = new SeatClaimReleased(
                    claimId: $claim->id,
                    registrationId: $claim->registration_id,
                    eventId: $claim->event_id,
                    brandId: $claim->brand_id,
                    reason: $reason,
                );

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

                $released[] = $claim;
            }

            return $released;
        });
    }
}
