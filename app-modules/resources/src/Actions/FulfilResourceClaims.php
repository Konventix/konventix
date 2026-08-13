<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Turns a confirmed registration's `resource_claim` grants into bookings.
 *
 * Takes scalars, never Registration's classes — the thin listener unpacks the
 * event, the same shape `IssueAdmissionsForRegistration` uses.
 *
 * **Fails soft, by design.** Resources' SPEC settles that "a RegistrationType
 * that grants resource claims still registers people correctly — the claims
 * simply stay unfulfilled rather than failing the registration, so switching
 * the feature on later can fulfil them retroactively." So an unknown type, a
 * missing event window, or a fully-booked resource skips that claim and
 * leaves the registration intact. A booth that cannot be allocated is an
 * organizer's problem to solve, not a reason to reject the exhibitor.
 *
 * Idempotent per registration: a replayed confirmation finds its bookings
 * already there and adds none.
 *
 * @phpstan-type Grant array{kind: string, role: string, quantity: int, resource_type_id: string|null}
 */
final class FulfilResourceClaims
{
    private const string CLAIM_GRANT_KIND = 'resource_claim';

    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
    ) {}

    /**
     * @param  list<Grant>  $grants
     * @return list<ResourceBooking>
     */
    public function handle(string $registrationId, string $eventId, string $brandId, array $grants): array
    {
        $alreadyFulfilled = ResourceBooking::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->exists();

        if ($alreadyFulfilled) {
            return [];
        }

        $window = ResourceEventWindow::forEvent($brandId, $eventId);

        if ($window === null) {
            // No projected window yet — nothing to book an interval against.
            // Left unfulfilled rather than guessed at.
            return [];
        }

        $created = [];

        foreach ($grants as $grant) {
            if ($grant['kind'] !== self::CLAIM_GRANT_KIND || $grant['resource_type_id'] === null) {
                continue;
            }

            $booking = $this->fulfil($grant, $registrationId, $brandId, $window);

            if ($booking !== null) {
                $created[] = $booking;
            }
        }

        return $created;
    }

    /**
     * @param  Grant  $grant
     */
    private function fulfil(array $grant, string $registrationId, string $brandId, ResourceEventWindow $window): ?ResourceBooking
    {
        $type = ResourceType::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereKey($grant['resource_type_id'])
            ->first();

        if ($type === null) {
            return null;
        }

        // First resource of the type with room. Which *specific* seat or booth
        // a claim gets is an allocation question the organizer answers in the
        // plan UI (SPEC's still-open transfer/allocation question); until then
        // first-fit is honest and reversible.
        $resources = Resource::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('resource_type_id', $type->id)
            ->where('is_published', true)
            ->orderBy('label')
            ->get();

        foreach ($resources as $resource) {
            try {
                $booking = $this->hold->handle(
                    resource: $resource,
                    holderType: ResourceBooking::HOLDER_REGISTRATION,
                    holderId: $registrationId,
                    startsAt: $window->starts_at,
                    endsAt: $window->ends_at,
                    quantity: $grant['quantity'],
                    registrationId: $registrationId,
                );

                // A claim from a confirmed registration is not provisional —
                // the commercial act already happened, so it goes straight to
                // firm rather than sitting on a hold clock nobody will answer.
                return $this->confirm->handle($booking);
            } catch (\Throwable) {
                // This resource is taken; try the next one.
                continue;
            }
        }

        return null;
    }
}
