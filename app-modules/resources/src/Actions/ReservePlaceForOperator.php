<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Events\PlaceSupplyChanged;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * An orga reservation (design §7.3, Q7): hold plus confirm in one
 * transaction with holder `operator` and the acting user's id, over the
 * event window like every whole-event booking. No claim, no registration.
 *
 * Reserving an occupied place fails through the exclusion constraint —
 * `HoldResourceBooking` lets the database refuse and raises
 * `ResourceUnavailableException`; nothing here checks first (ADR-0020). A
 * blocked place may be reserved: blocking keeps registrants off it, which
 * is exactly when the orga wants it. A removed place may not.
 *
 * This transaction nests `HoldResourceBooking`'s own exactly the way
 * `AssignPlacesToRegistration`'s does, so a genuine `40P01` reaches this
 * method as Laravel's `DeadlockException` (a `PDOException`, not a
 * `QueryException` — see `AssignPlacesToRegistration`'s class docblock for
 * the mechanism) rather than through `HoldResourceBooking`'s own
 * `QueryException`-only catch. Both that and a raw `QueryException` a
 * differently-nested caller could still produce are normalised here to this
 * Action's own contract exception, `ResourceUnavailableException`, so an
 * operator reserving a seat someone else is buying at that instant gets the
 * same clean refusal `ResourceUnavailableException` already gives for the
 * sequential case — never an uncaught crash.
 *
 * A successful reservation also consumes one unit of the category's
 * Registration quota, exactly as a sale would (design §12b/#282):
 * `PlaceSupplyChanged` fires with `reason: reserved`, `direction: consumed`.
 *
 * `Permission::BookOnBehalf` is checked by the console controller.
 */
final class ReservePlaceForOperator
{
    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
    ) {}

    public function handle(Resource $place, string $actorUserId): ResourceBooking
    {
        if ($place->trashed()) {
            throw ValidationException::withMessages(['placeId' => [__('This place was removed from the plan.')]]);
        }

        return DB::transaction(function () use ($place, $actorUserId): ResourceBooking {
            $type = ResourceType::withoutBrandScope()->whereKey($place->resource_type_id)->firstOrFail();
            $window = ResourceEventWindow::forEvent($place->brand_id, $type->event_id);

            if ($window === null) {
                throw ValidationException::withMessages(['placeId' => [__('The event window is not projected yet; run event:replay-details.')]]);
            }

            try {
                $booking = $this->hold->handle(
                    resource: $place,
                    holderType: ResourceBooking::HOLDER_OPERATOR,
                    holderId: $actorUserId,
                    startsAt: $window->starts_at,
                    endsAt: $window->ends_at,
                    quantity: 1,
                );

                $confirmed = $this->confirm->handle($booking);

                // Idiom copied from `ReleaseResourceBooking`: whatever brand
                // is ambient now is captured and restored around the
                // deferred dispatch alone, since a console request usually
                // has none.
                $brand = app(BrandContext::class)->current();
                $event = new PlaceSupplyChanged(
                    changeId: (string) Str::ulid(),
                    brandId: $place->brand_id,
                    eventId: $type->event_id,
                    resourceTypeId: $type->id,
                    reason: 'reserved',
                    direction: 'consumed',
                    places: 1,
                );

                DB::afterCommit(static function () use ($event, $brand): void {
                    if ($brand === null) {
                        event($event);

                        return;
                    }

                    app(BrandContext::class)->withBrand($brand, static function () use ($event): void {
                        event($event);
                    });
                });

                return $confirmed;
            } catch (DeadlockException) {
                throw ResourceUnavailableException::alreadyBooked($place->id);
            } catch (QueryException $exception) {
                if (AssignPlacesToRegistration::isRaceFailure($exception)) {
                    throw ResourceUnavailableException::alreadyBooked($place->id);
                }

                throw $exception;
            }
        });
    }
}
