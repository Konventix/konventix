<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Turns a registration's `resource_claim` grants into bookings — at
 * confirmation (`amendmentId` null) and for every amendment (ADR-0036,
 * `amendmentId` set).
 *
 * Takes scalars, never Registration's classes — the thin listeners unpack
 * the event, the same shape `IssueAdmissionsForRegistration` uses.
 *
 * **Fails soft, by design.** Resources' SPEC settles that "a RegistrationType
 * that grants resource claims still registers people correctly — the claims
 * simply stay unfulfilled rather than failing the registration, so switching
 * the feature on later can fulfil them retroactively." So an unknown type, a
 * missing event window, or a fully-booked type skips that claim and leaves
 * the registration intact. Only `ResourceUnavailableException` is caught:
 * it is the one outcome that means "try the next resource"; anything else
 * is a real failure and surfaces.
 *
 * **Idempotent per issuing occasion**, keyed on `(registration_id,
 * amendment_id)`: a replayed confirmation or amendment finds its bookings
 * and adds none, while an amendment on a registration that already has
 * bookings from its confirmation is fulfilled — the old "any booking for
 * this registration" guard silently dropped every amendment.
 *
 * The idempotency check excludes **seat bookings** (item 4 of the
 * 2026-09-10 fix wave, #278): `AssignPlacesToRegistration` writes into the
 * same `(brand_id, registration_id, amendment_id)` key space this Action
 * checks, but with `claim_id` set — this Action's own bookings never carry
 * one. Without the `claim_id` filter, a confirmation that arrived before
 * the event window was projected (this Action fulfils nothing, §"window
 * projection" below) followed by the registrant picking a seat left a row
 * that satisfied `alreadyFulfilled` on replay, so a later, correctly-timed
 * replay of the same confirmation silently never fulfilled the
 * `resource_claim` grant it was meant to retry.
 *
 * **Quantity `n` is `n` seats.** In unit mode a claim for two seats makes
 * two separate bookings on two resources (a unit cannot hold quantity 2);
 * in quantity mode it is one booking drawing `n` off one resource's
 * capacity (ADR-0034). Candidates are tried in *natural* label order, so
 * `A-2` comes before `A-10` — first-fit that reads like the plan.
 *
 * Only `resource_claim` grants are handled here. `seating` grants become
 * `resource_claims` rows (slice S3); unknown kinds are Admission's or a
 * future module's and are skipped without complaint.
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
    public function handle(string $registrationId, string $eventId, string $brandId, array $grants, ?string $amendmentId = null): array
    {
        $alreadyFulfilled = ResourceBooking::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->where('amendment_id', $amendmentId)
            ->whereNull('claim_id')
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

            array_push($created, ...$this->fulfil($grant, $registrationId, $brandId, $amendmentId, $window));
        }

        return $created;
    }

    /**
     * @param  Grant  $grant
     * @return list<ResourceBooking>
     */
    private function fulfil(array $grant, string $registrationId, string $brandId, ?string $amendmentId, ResourceEventWindow $window): array
    {
        $type = ResourceType::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereKey($grant['resource_type_id'])
            ->first();

        if ($type === null) {
            return [];
        }

        // Ordered in the database, not loaded whole and sorted in PHP — a
        // dozen booths and a 160-place hall must cost the same query. Plan,
        // zone and group group the candidates the way the plan itself
        // groups them; the trailing digit run of the ref (falling back to
        // the label) gives natural order within a group — "A-2" before
        // "A-10" — for the row+number pattern design §5 uses throughout.
        $candidates = Resource::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('resource_type_id', $type->id)
            ->bookable()
            ->orderByRaw('plan_id ASC NULLS FIRST')
            ->orderByRaw('zone_id ASC NULLS FIRST')
            ->orderByRaw('group_id ASC NULLS FIRST')
            ->orderByRaw("regexp_replace(COALESCE(ref, label), '[0-9]+$', '') ASC")
            ->orderByRaw("(substring(COALESCE(ref, label) from '[0-9]+$'))::bigint ASC NULLS FIRST")
            ->orderByRaw('COALESCE(ref, label) ASC')
            ->get();

        $wanted = max(1, $grant['quantity']);

        if ($type->mode === CapacityMode::Quantity) {
            foreach ($candidates as $resource) {
                $booking = $this->book($resource, $wanted, $registrationId, $amendmentId, $window);

                if ($booking !== null) {
                    return [$booking];
                }
            }

            return [];
        }

        $booked = [];

        foreach ($candidates as $resource) {
            if (count($booked) === $wanted) {
                break;
            }

            $booking = $this->book($resource, 1, $registrationId, $amendmentId, $window);

            if ($booking !== null) {
                $booked[] = $booking;
            }
        }

        // Fewer than `wanted` free: the ones that were free are kept. A
        // partial fulfilment is an organizer's problem to solve in the plan,
        // not a reason to give back what the registration already has.
        return $booked;
    }

    private function book(Resource $resource, int $quantity, string $registrationId, ?string $amendmentId, ResourceEventWindow $window): ?ResourceBooking
    {
        try {
            $booking = $this->hold->handle(
                resource: $resource,
                holderType: ResourceBooking::HOLDER_REGISTRATION,
                holderId: $registrationId,
                startsAt: $window->starts_at,
                endsAt: $window->ends_at,
                quantity: $quantity,
                registrationId: $registrationId,
                amendmentId: $amendmentId,
            );
        } catch (ResourceUnavailableException) {
            // This resource is taken for the interval; try the next one.
            return null;
        }

        // A claim from a confirmed registration is not provisional — the
        // commercial act already happened, so it goes straight to firm
        // rather than sitting on a hold clock nobody will answer.
        return $this->confirm->handle($booking);
    }
}
