<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Permissions\PermissionHolders;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\Permission;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Events\ResourceBookingRefused;
use Konventix\Resources\Events\SeatClaimPlaced;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;

/**
 * Seats one admission on one place (#292 §6.3) — the pick path behind the
 * per-ticket picker, beside `AssignPlacesToRegistration`, which keeps the
 * registration-level path for the console reseat, the CLI and a claim
 * whose registration has no admissions in the read model.
 *
 * Same transaction discipline as its sibling: the claim row is locked so
 * two picks on the same group serialise; the admission's own live booking
 * is released first (`repicked`) so re-choosing the same place is not a
 * self-collision; the place is held and confirmed in one go. The database
 * decides every race — `23P01` for a place somebody else took, `23505` on
 * `resource_bookings_one_place_per_admission` for a second place for the
 * same ticket, `40P01` for a lock cycle — and each surfaces as the
 * `placeIds` validation error naming the place.
 *
 * The cap is the claim's `quantity`, counted across the claim's occupying
 * bookings bound to *other* admissions plus its unbound ones. One
 * exception: picking a place the claim already holds **unbound** rebinds
 * it — "this seat is this ticket's" — which is also how bookings made
 * before this Action existed migrate onto tickets.
 *
 * A null place gives the seat up. The claim goes back to `unplaced` only
 * when no booking of it remains.
 *
 * Actors: `registrant` when the user is the claim's registrant (the
 * manager) **or** the admission's holder; `operator` needs
 * `resources.book-on-behalf`. Last write wins by construction.
 */
final class AssignPlaceToAdmission
{
    public const string ACTOR_REGISTRANT = AssignPlacesToRegistration::ACTOR_REGISTRANT;

    public const string ACTOR_OPERATOR = AssignPlacesToRegistration::ACTOR_OPERATOR;

    public const string REASON_REPICKED = AssignPlacesToRegistration::REASON_REPICKED;

    public const string REASON_GIVEN_UP = 'given_up';

    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
        private readonly ReleaseResourceBooking $release,
    ) {}

    /**
     * @throws ValidationException keyed `placeIds`
     * @throws AuthorizationException
     */
    public function handle(ResourceClaim $claim, ResourceAdmission $admission, ?string $placeId, string $actorType, ?string $actorUserId): ?ResourceBooking
    {
        $this->guardActor($claim, $admission, $actorType, $actorUserId);

        $raceRefusalReason = null;

        try {
            return $this->assign($claim, $admission, $placeId, $actorType, $raceRefusalReason);
        } catch (ValidationException $e) {
            if ($raceRefusalReason !== null) {
                event(new ResourceBookingRefused(
                    brandId: $claim->brand_id,
                    eventId: $claim->event_id,
                    registrationId: $claim->registration_id,
                    reason: $raceRefusalReason,
                ));
            }

            throw $e;
        }
    }

    private function assign(ResourceClaim $claim, ResourceAdmission $admission, ?string $placeId, string $actorType, ?string &$raceRefusalReason): ?ResourceBooking
    {
        return DB::transaction(function () use ($claim, $admission, $placeId, $actorType, &$raceRefusalReason): ?ResourceBooking {
            $locked = ResourceClaim::withoutBrandScope()
                ->whereKey($claim->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state === ClaimState::Released) {
                throw $this->refuse(__('This registration no longer holds a seat claim.'));
            }

            if ($locked->seating_mode === SeatingMode::Assign && $actorType !== self::ACTOR_OPERATOR) {
                throw $this->refuse(__('Your seat will be assigned by the organiser.'));
            }

            if ($admission->registration_id !== $locked->registration_id || $admission->isRevoked()) {
                throw $this->refuse(__('This ticket cannot be seated on this registration.'));
            }

            $this->releaseOwnBooking($locked, $admission);

            if ($placeId === null) {
                $this->settleClaimState($locked, $claim);

                return null;
            }

            $place = $this->eligiblePlace($locked, $placeId);

            if ($place === null) {
                throw $this->refuse(__('Place :ref is not available for this registration.', [
                    'ref' => $this->refOf($placeId, $locked->brand_id),
                ]));
            }

            // The group's own unbound booking on this place, if any: taken
            // over rather than refused, and it never counts against the cap.
            $unbound = ResourceBooking::withoutBrandScope()
                ->where('claim_id', $locked->id)
                ->where('resource_id', $place->id)
                ->whereNull('admission_id')
                ->whereIn('state', BookingState::occupyingValues())
                ->lockForUpdate()
                ->first();

            if ($unbound !== null) {
                $this->release->handle($unbound, BookingState::Released, self::REASON_REPICKED);
            } elseif ($this->occupiedByOthers($locked) >= $locked->quantity) {
                throw $this->refuse(__("Your group's seat allowance is used up."));
            }

            $window = ResourceEventWindow::forEvent($locked->brand_id, $locked->event_id);

            if ($window === null) {
                throw $this->refuse(__('The event window is not projected yet; please try again later.'));
            }

            try {
                $booking = $this->hold->handle(
                    resource: $place,
                    holderType: ResourceBooking::HOLDER_REGISTRATION,
                    holderId: $locked->registration_id,
                    startsAt: $window->starts_at,
                    endsAt: $window->ends_at,
                    quantity: 1,
                    registrationId: $locked->registration_id,
                    claimId: $locked->id,
                    admissionId: $admission->id,
                );

                $confirmed = $this->confirm->handle($booking);
            } catch (ResourceUnavailableException) {
                $raceRefusalReason = 'unavailable';

                throw $this->taken($place);
            } catch (DeadlockException) {
                $raceRefusalReason = 'deadlock';

                throw $this->taken($place);
            } catch (QueryException $exception) {
                if (AssignPlacesToRegistration::isRaceFailure($exception)
                    || str_contains($exception->getMessage(), 'resource_bookings_one_place_per_admission')) {
                    $raceRefusalReason = (string) $exception->getCode() === '40P01' ? 'deadlock' : 'unavailable';

                    throw $this->taken($place);
                }

                throw $exception;
            }

            $locked->forceFill(['state' => ClaimState::Placed])->save();
            $claim->setRawAttributes($locked->getAttributes(), true);

            $this->dispatchAfterCommit(new SeatClaimPlaced(
                claimId: $locked->id,
                registrationId: $locked->registration_id,
                eventId: $locked->event_id,
                brandId: $locked->brand_id,
                placeIds: [$place->id],
            ));

            return $confirmed;
        });
    }

    private function releaseOwnBooking(ResourceClaim $claim, ResourceAdmission $admission): void
    {
        $own = ResourceBooking::withoutBrandScope()
            ->where('claim_id', $claim->id)
            ->where('admission_id', $admission->id)
            ->whereIn('state', BookingState::occupyingValues())
            ->lockForUpdate()
            ->get();

        foreach ($own as $booking) {
            $this->release->handle($booking, BookingState::Released, self::REASON_REPICKED);
        }
    }

    /**
     * Occupying bookings of the claim that are not this admission's own —
     * other tickets' and the unbound ones — which is what the allowance
     * has already spent.
     */
    private function occupiedByOthers(ResourceClaim $claim): int
    {
        return ResourceBooking::withoutBrandScope()
            ->where('claim_id', $claim->id)
            ->whereIn('state', BookingState::occupyingValues())
            ->count();
    }

    private function settleClaimState(ResourceClaim $locked, ResourceClaim $claim): void
    {
        $state = $this->occupiedByOthers($locked) > 0 ? ClaimState::Placed : ClaimState::Unplaced;

        $locked->forceFill(['state' => $state])->save();
        $claim->setRawAttributes($locked->getAttributes(), true);
    }

    private function eligiblePlace(ResourceClaim $claim, string $placeId): ?Resource
    {
        return Resource::withoutBrandScope()
            ->select('resources.*')
            ->join('resource_plans', 'resource_plans.id', '=', 'resources.plan_id')
            ->where('resources.brand_id', $claim->brand_id)
            ->where('resources.id', $placeId)
            ->whereNull('resources.deleted_at')
            ->where('resources.blocked', false)
            ->whereIn('resources.resource_type_id', $claim->resource_type_ids)
            ->where('resource_plans.event_id', $claim->event_id)
            ->whereNotNull('resource_plans.published_at')
            ->first();
    }

    private function guardActor(ResourceClaim $claim, ResourceAdmission $admission, string $actorType, ?string $actorUserId): void
    {
        if ($actorType === self::ACTOR_REGISTRANT) {
            $isManager = $actorUserId !== null && $claim->registrant_user_id === $actorUserId;
            $isHolder = $actorUserId !== null && $admission->holder_user_id === $actorUserId;

            if (! $isManager && ! $isHolder) {
                throw new AuthorizationException('This ticket belongs to somebody else.');
            }

            return;
        }

        if ($actorType === self::ACTOR_OPERATOR) {
            if ($actorUserId === null) {
                return;
            }

            $holders = app(PermissionHolders::class)
                ->userIdsWithPermission($claim->brand_id, Permission::BookOnBehalf->value);

            if (! in_array($actorUserId, $holders, true)) {
                throw new AuthorizationException(sprintf('Seating a ticket on behalf of a registration needs %s.', Permission::BookOnBehalf->value));
            }

            return;
        }

        throw new InvalidArgumentException(sprintf('Unknown actor type [%s].', $actorType));
    }

    /**
     * Deferred to the outermost commit, the ambient brand captured and
     * restored around it — exactly as `AssignPlacesToRegistration` does.
     */
    private function dispatchAfterCommit(object $event): void
    {
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
    }

    private function taken(Resource $place): ValidationException
    {
        return $this->refuse(__('Place :ref has just been taken by someone else.', [
            'ref' => $place->ref ?? $place->label,
        ]));
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['placeIds' => $message]);
    }

    private function refOf(string $placeId, string $brandId): string
    {
        $place = Resource::withoutBrandScope()->whereKey($placeId)->where('brand_id', $brandId)->first();

        return $place !== null ? ($place->ref ?? $place->label ?? $placeId) : $placeId;
    }
}
