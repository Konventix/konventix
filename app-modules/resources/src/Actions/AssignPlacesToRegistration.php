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
use Konventix\Resources\Events\ResourceBookingRefused;
use Konventix\Resources\Events\SeatClaimPlaced;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Resource;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it (the same trap fixed in tests/Helpers.php).
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;

/**
 * Places a seat claim on concrete places (seating design §7.3) — the one
 * pick path, used by the picker's controller, the console reseat and
 * `resources:assign-place`.
 *
 * One transaction: the claim row is locked `FOR UPDATE` so two picks for the
 * same claim serialise; the claim's live bookings are released first
 * (reason `repicked`) so picking the same place again is not a self-collision;
 * then, per place, `HoldResourceBooking` immediately followed by
 * `ConfirmResourceBooking` — the seat booking is never a clocked hold, it is
 * firm the moment the transaction commits (ADR-0060's second hold kind).
 *
 * **Who wins a race is the database's call.** The exclusion constraint
 * (ADR-0020) refuses the loser's insert with `23P01`; a lock cycle between
 * two pickers ends in `40P01`. Both surface here as a `placeIds` validation
 * error naming the place, and the loser's transaction rolls back whole —
 * no booking, claim still `unplaced`. `placeIds` are sorted and de-duplicated
 * before locking so two pickers touching the same two places lock them in
 * the same order. `HoldResourceBooking`'s own transaction is always nested
 * inside this one, so a real `40P01` reaches this method as Laravel's
 * `DeadlockException` (a `PDOException`, not a `QueryException` —
 * `ManagesTransactions::handleTransactionException()` wraps it precisely
 * because the nesting level is above one) rather than as the SQLSTATE
 * `isRaceFailure()` matches; both are caught here and treated the same,
 * a fact `resources:probe-seat-race` proved by racing real processes where
 * a single Pest transaction cannot.
 *
 * Eligibility is a query over the projection, never over the plan document
 * (seating design §6.2): a place is a non-deleted, non-blocked `resources`
 * row of a *published* plan of the claim's event whose category is one the
 * claim may choose.
 *
 * Actors: `registrant` must own the claim (`registrant_user_id`);
 * `operator` with a user id needs `resources.book-on-behalf` on the brand
 * (checked through Platform's `PermissionHolders`, so this module imports no
 * User model — ADR-0004); `operator` with a null user id is the trusted CLI
 * path (`resources:assign-place`), recorded in the design's §13.
 *
 * `SeatClaimPlaced` is deferred to the outermost commit via `DB::afterCommit()`,
 * the same mechanism `CreateSeatClaims`, `HoldResourceBooking`,
 * `ConfirmResourceBooking` and `ReleaseResourceBooking` use (design §6.1 step
 * 7): this Action is the outermost transaction at every call site today, but
 * nothing enforces that, and a future caller that wraps it must not see the
 * claim announced placed for a pick its own rollback then undoes. Whatever
 * brand is ambient right now is captured and restored around the deferred
 * dispatch, exactly as those Actions do.
 *
 * A refused pick is reported as `ResourceBookingRefused` so
 * `resource_bookings_total{outcome="refused"}` moves (ADR-0059) — but only
 * once `handle()`'s own try/catch has the exception back from `assign()`,
 * never from inside its transaction: a real deadlock or exclusion
 * violation leaves Postgres refusing every statement, the metrics
 * listener's brand-slug lookup included, until the rollback that unwinding
 * the transaction performs actually completes.
 */
final class AssignPlacesToRegistration
{
    public const string ACTOR_REGISTRANT = 'registrant';

    public const string ACTOR_OPERATOR = 'operator';

    public const string REASON_REPICKED = 'repicked';

    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
        private readonly ReleaseResourceBooking $release,
    ) {}

    /**
     * @param  list<string>  $placeIds
     * @return list<ResourceBooking>
     *
     * @throws ValidationException keyed `placeIds`
     * @throws AuthorizationException
     */
    public function handle(ResourceClaim $claim, array $placeIds, string $actorType, ?string $actorUserId): array
    {
        $this->guardActor($claim, $actorType, $actorUserId);

        $placeIds = array_values(array_unique($placeIds));
        sort($placeIds);

        // Set by a race-shaped catch below, read once the transaction has
        // fully unwound (see the try/catch this method closes with): a real
        // deadlock or exclusion violation leaves Postgres refusing every
        // statement — including a metrics listener's own query — until the
        // rollback below completes, which `resources:probe-seat-race`'s
        // real process contention proved and a single-process Pest
        // transaction cannot.
        $raceRefusalReason = null;

        try {
            $bookings = $this->assign($claim, $placeIds, $raceRefusalReason);
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

        return $bookings;
    }

    /**
     * @param  list<string>  $placeIds
     * @return list<ResourceBooking>
     */
    private function assign(ResourceClaim $claim, array $placeIds, ?string &$raceRefusalReason): array
    {
        return DB::transaction(function () use ($claim, $placeIds, &$raceRefusalReason): array {
            $locked = ResourceClaim::withoutBrandScope()
                ->whereKey($claim->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state === ClaimState::Released) {
                throw $this->refuse(__('This registration no longer holds a seat claim.'));
            }

            if ($placeIds === []) {
                throw $this->refuse(__('Choose at least one place.'));
            }

            if (count($placeIds) > $locked->quantity) {
                throw $this->refuse(__('You may choose at most :count place(s).', ['count' => $locked->quantity]));
            }

            $places = $this->eligiblePlaces($locked, $placeIds);
            $ineligible = array_values(array_diff($placeIds, array_keys($places)));

            if ($ineligible !== []) {
                throw $this->refuse(__('Place :ref is not available for this registration.', [
                    'ref' => $this->refOf($ineligible[0], $locked->brand_id),
                ]));
            }

            $window = ResourceEventWindow::forEvent($locked->brand_id, $locked->event_id);

            if ($window === null) {
                throw $this->refuse(__('The event window is not projected yet; please try again later.'));
            }

            $this->releaseCurrentBookings($locked);

            $created = [];

            foreach ($placeIds as $placeId) {
                $place = $places[$placeId];

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
                    );

                    $created[] = $this->confirm->handle($booking);
                } catch (ResourceUnavailableException) {
                    $raceRefusalReason = 'unavailable';

                    throw $this->taken($place);
                } catch (DeadlockException) {
                    // A deadlock inside HoldResourceBooking's own nested
                    // transaction: Laravel already rolled that savepoint
                    // back and reports it as this PDOException subtype
                    // rather than the QueryException isRaceFailure() reads
                    // (see the class docblock). Always a race — hold then
                    // confirm never deadlocks with itself.
                    $raceRefusalReason = 'deadlock';

                    throw $this->taken($place);
                } catch (QueryException $exception) {
                    if (self::isRaceFailure($exception)) {
                        $raceRefusalReason = (string) $exception->getCode() === '40P01' ? 'deadlock' : 'unavailable';

                        throw $this->taken($place);
                    }

                    throw $exception;
                }
            }

            $locked->forceFill(['state' => ClaimState::Placed])->save();

            // Refresh the caller's instance so it reads what was written.
            $claim->setRawAttributes($locked->getAttributes(), true);

            $event = new SeatClaimPlaced(
                claimId: $locked->id,
                registrationId: $locked->registration_id,
                eventId: $locked->event_id,
                brandId: $locked->brand_id,
                placeIds: $placeIds,
            );

            // Deferred to the outermost commit (design §6.1 step 7): see the
            // class docblock. Whatever brand is ambient right now is
            // captured and restored around the deferred dispatch, because by
            // the time it runs a wrapping caller's own withBrand() block has
            // already put the previous brand back (see ReleaseResourceBooking).
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

            return $created;
        });
    }

    /**
     * The two SQLSTATEs a lost race produces: `23P01` (exclusion violation)
     * and `40P01` (deadlock), for a `QueryException` reaching this method
     * from a transaction at nesting level one — `resources:assign-place`
     * calling `HoldResourceBooking` with no wrapping transaction of its own,
     * or a direct unit test. `HoldResourceBooking` already converts the
     * exclusion violation into `ResourceUnavailableException` when it sees
     * it; this catches the constraint name too, for a driver that reports
     * the code differently. The far more common case — this Action's own
     * transaction nests `HoldResourceBooking`'s — never reaches here as a
     * `QueryException` at all; see the `DeadlockException` catch above and
     * the class docblock.
     */
    public static function isRaceFailure(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        return $code === '23P01'
            || $code === '40P01'
            || str_contains($exception->getMessage(), 'resource_bookings_no_overlap');
    }

    private function guardActor(ResourceClaim $claim, string $actorType, ?string $actorUserId): void
    {
        if ($actorType === self::ACTOR_REGISTRANT) {
            if ($actorUserId === null || $claim->registrant_user_id !== $actorUserId) {
                throw new AuthorizationException('This seat claim belongs to another registrant.');
            }

            return;
        }

        if ($actorType === self::ACTOR_OPERATOR) {
            if ($actorUserId === null) {
                // The console command: no user behind it, trusted by being
                // able to run artisan at all (seating design §13).
                return;
            }

            $holders = app(PermissionHolders::class)
                ->userIdsWithPermission($claim->brand_id, Permission::BookOnBehalf->value);

            if (! in_array($actorUserId, $holders, true)) {
                throw new AuthorizationException(sprintf(
                    'Placing on behalf of a registration needs %s.',
                    Permission::BookOnBehalf->value,
                ));
            }

            return;
        }

        throw new InvalidArgumentException(sprintf('Unknown actor type [%s].', $actorType));
    }

    /**
     * The requested places that this claim may actually take, keyed by id.
     * Anything the query drops is ineligible for one of the listed reasons;
     * the caller reports the first missing one by ref.
     *
     * @param  list<string>  $placeIds
     * @return array<string, BookableResource>
     */
    private function eligiblePlaces(ResourceClaim $claim, array $placeIds): array
    {
        $places = Resource::withoutBrandScope()
            ->select('resources.*')
            ->join('resource_plans', 'resource_plans.id', '=', 'resources.plan_id')
            ->where('resources.brand_id', $claim->brand_id)
            ->whereIn('resources.id', $placeIds)
            ->whereNull('resources.deleted_at')
            ->where('resources.blocked', false)
            ->whereIn('resources.resource_type_id', $claim->resource_type_ids)
            ->where('resource_plans.event_id', $claim->event_id)
            ->whereNotNull('resource_plans.published_at')
            ->get();

        return $places->keyBy('id')->all();
    }

    /**
     * Every live booking this claim holds goes back before the new set is
     * taken (seating design §7.5 "Registrant re-picks"). Inside the same
     * transaction, so a refused re-pick leaves the old places in place.
     */
    private function releaseCurrentBookings(ResourceClaim $claim): void
    {
        $active = ResourceBooking::withoutBrandScope()
            ->where('claim_id', $claim->id)
            ->whereIn('state', BookingState::occupyingValues())
            ->lockForUpdate()
            ->get();

        foreach ($active as $booking) {
            $this->release->handle($booking, BookingState::Released, self::REASON_REPICKED);
        }
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

    /**
     * The human key of a place the claim may not take, for the error text —
     * or the id when the row is gone or belongs to another brand.
     *
     * Brand-filtered from the claim (item 3 of the 2026-09-10 fix wave,
     * #278): a registrant reaches this path by posting a place id, and an
     * id belonging to another brand entirely was previously still looked
     * up `withoutBrandScope()` with no brand re-filter, handing that
     * brand's own place ref back in the error text.
     */
    private function refOf(string $placeId, string $brandId): string
    {
        $place = Resource::withoutBrandScope()->whereKey($placeId)->where('brand_id', $brandId)->first();

        // Not `$place?->ref ?? ...`: Larastan mistypes `Builder::first()`
        // here as never-null, which makes the nullsafe form a phpstan
        // false positive (`nullsafe.neverNull`) — an explicit null check
        // sidesteps it without changing the behaviour. A cross-brand id
        // legitimately finds nothing here now, unlike before the fix.
        return $place !== null ? ($place->ref ?? $place->label ?? $placeId) : $placeId;
    }
}
