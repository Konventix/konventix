<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceClaimReductionReceipt;

/**
 * The partial counterpart of `ReleaseSeatClaims` (D-REG-15): the
 * registration bought fewer units, so its seat entitlement shrinks by the
 * places those units were worth — it never disappears.
 *
 * `$removedSeats` arrives already multiplied (the reduction event's
 * `removedGrants` carries `grant quantity × removed units`, exactly as
 * `RegistrationConfirmed` does), so this Action does no arithmetic on
 * quantities beyond the subtraction itself and never learns that a
 * registration has a quantity.
 *
 * **Never the whole claim.** A reduction leaves at least one unit standing
 * by construction (`ReduceRegistrationQuantity` refuses to remove the
 * last), and the new quantity is floored at one here as well: a claim of
 * zero would make the picker offer nothing while `resource_claims` still
 * said the entitlement existed. Cancelling the registration is what ends a
 * claim, and `ReleaseSeatClaims` owns that.
 *
 * **Surplus bookings, unattended first.** Once the claim caps fewer places
 * than it currently occupies, the extra bookings have to go back — through
 * `ReleaseResourceBooking` like every other release (`.ai/rules/seating.md`:
 * bookings are only ever written by the three Actions). Bookings with no
 * `admission_id` go first: a place bound to a ticket is a promise to a
 * named person, and Admission has already refused the reduction if there
 * were not enough free tickets, so a bound place should never actually be
 * reached. Within each group the newest booking is released first, so the
 * seats a group picked earliest are the ones they keep.
 *
 * **Idempotent per reduction** through `resource_claim_reduction_receipts`:
 * a decrement is not a state a replay converges on, so the fact that this
 * reduction was applied is a row, not an inference from the quantity.
 *
 * Runs each release with the booking's own brand in context, exactly as
 * `ReleaseBookingsForRegistration` does — the reduction is made from the
 * operator console, which resolves no brand at all (ADR-0029).
 */
final class ReduceSeatClaim
{
    public const string REASON_QUANTITY_REDUCED = 'registration_quantity_reduced';

    public function __construct(
        private readonly ReleaseResourceBooking $release,
    ) {}

    /**
     * @return list<ResourceClaim> the claims actually shrunk
     */
    public function handle(string $registrationId, string $reductionId, int $removedSeats): array
    {
        if ($removedSeats < 1) {
            return [];
        }

        return DB::transaction(function () use ($registrationId, $reductionId, $removedSeats): array {
            $claims = ResourceClaim::withoutBrandScope()
                ->where('registration_id', $registrationId)
                ->whereIn('state', [ClaimState::Unplaced->value, ClaimState::Placed->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $reduced = [];

            foreach ($claims as $claim) {
                $receipt = ResourceClaimReductionReceipt::withoutBrandScope()
                    ->where('claim_id', $claim->id)
                    ->where('reduction_id', $reductionId)
                    ->exists();

                if ($receipt) {
                    continue;
                }

                // Floored at one: a claim is an entitlement, and the
                // reduction that would zero it is a cancellation instead.
                $newQuantity = max(1, $claim->quantity - $removedSeats);

                $this->recordReceipt($claim, $reductionId, $claim->quantity - $newQuantity);

                if ($newQuantity === $claim->quantity) {
                    continue;
                }

                $claim->forceFill(['quantity' => $newQuantity])->save();

                $this->releaseSurplusBookings($claim, $newQuantity);

                $reduced[] = $claim;
            }

            return $reduced;
        });
    }

    private function recordReceipt(ResourceClaim $claim, string $reductionId, int $units): void
    {
        $receipt = new ResourceClaimReductionReceipt([
            'claim_id' => $claim->id,
            'reduction_id' => $reductionId,
            'units' => $units,
        ]);

        // brand_id off the claim, not ambient context (ADR-0010).
        $receipt->forceFill(['brand_id' => $claim->brand_id])->save();
    }

    /**
     * Gives back whatever the claim now occupies above its new cap.
     * Unattended bookings first, newest first inside each group.
     */
    private function releaseSurplusBookings(ResourceClaim $claim, int $newQuantity): void
    {
        $bookings = ResourceBooking::withoutBrandScope()
            ->where('claim_id', $claim->id)
            ->whereIn('state', BookingState::occupyingValues())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $surplus = $bookings->count() - $newQuantity;

        if ($surplus < 1) {
            return;
        }

        $ordered = [
            // Unattended: no ticket is bound to the place, so nobody is
            // promised it. Newest first — the group keeps what it picked
            // first.
            ...$bookings->whereNull('admission_id')->sortByDesc('id')->values()->all(),
            ...$bookings->whereNotNull('admission_id')->sortByDesc('id')->values()->all(),
        ];

        foreach (array_slice($ordered, 0, $surplus) as $booking) {
            // Plain lookup: `Brand` carries no global scope to strip
            // (`ReleaseBookingsForRegistration`'s own note, #278).
            $brand = Brand::query()->findOrFail($booking->brand_id);

            app(BrandContext::class)->withBrand(
                $brand,
                fn (): ResourceBooking => $this->release->handle(
                    $booking,
                    BookingState::Released,
                    self::REASON_QUANTITY_REDUCED,
                ),
            );
        }
    }
}
