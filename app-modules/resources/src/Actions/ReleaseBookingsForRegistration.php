<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\ResourceBooking;

/**
 * The counterpart of `FulfilResourceClaims`: the registration ended, so
 * every booking it holds ends with it (SPEC.md "Consumes" —
 * `RegistrationCancelled`, `RegistrationRejected`; design doc §7.2).
 *
 * `RegistrationCancelled` and `RegistrationRejected` carry no brand id, so
 * the lookup is by registration id alone under `withoutBrandScope()` —
 * ADR-0010's named escape hatch, safe here because a registration ULID is
 * globally unique and every booking it produced belongs to that
 * registration's own brand. Both the `registration_id` column (claims) and
 * the `registration` holder (direct bookings on behalf of one) are matched.
 *
 * Every release runs with the booking's own brand in context: the caller
 * may have none (webhook, scheduler), and the listeners downstream of
 * `ResourceBookingReleased` — metrics in S6, claim state in S3 — read the
 * ambient brand. `BrandContext::withBrand()` restores whatever was there
 * before, so a console request on the operator host ends brand-less again.
 *
 * Idempotent: only occupying bookings are selected, so a second
 * cancellation finds nothing left and emits nothing.
 */
final class ReleaseBookingsForRegistration
{
    public const string REASON_REGISTRATION_CANCELLED = 'registration_cancelled';

    public const string REASON_REGISTRATION_REJECTED = 'registration_rejected';

    public function __construct(
        private readonly ReleaseResourceBooking $release,
    ) {}

    /**
     * @return list<ResourceBooking> the bookings actually released
     */
    public function handle(string $registrationId, BookingState $to, string $reason): array
    {
        return DB::transaction(function () use ($registrationId, $to, $reason): array {
            $bookings = ResourceBooking::withoutBrandScope()
                ->where(function (Builder $query) use ($registrationId): void {
                    $query->where('registration_id', $registrationId)
                        ->orWhere(function (Builder $holder) use ($registrationId): void {
                            $holder->where('holder_type', ResourceBooking::HOLDER_REGISTRATION)
                                ->where('holder_id', $registrationId);
                        });
                })
                ->whereIn('state', BookingState::occupyingValues())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $released = [];

            foreach ($bookings as $booking) {
                // Plain lookup, no `withoutGlobalScopes()` (item 7 of the
                // 2026-09-10 fix wave, #278): `Brand` carries no global
                // scope to strip, so the escape hatch documents a
                // boundary that does not exist — the same finding
                // `ModuleGate::isActiveForBrand()` fixed (#271).
                $brand = Brand::query()->findOrFail($booking->brand_id);

                $released[] = app(BrandContext::class)->withBrand(
                    $brand,
                    fn (): ResourceBooking => $this->release->handle($booking, $to, $reason),
                );
            }

            return $released;
        });
    }
}
