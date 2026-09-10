<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\PublishPlan;
use Konventix\Resources\Actions\SavePlan;
use Konventix\Resources\Actions\UnpublishPlan;
use Konventix\Resources\Actions\ValidatePlan;
use Konventix\Resources\Data\PlanConflictResolutionData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\ConflictReason;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Events\PlanPublished;
use Konventix\Resources\Events\PlanSaved;
use Konventix\Resources\Events\PlanUnpublished;
use Konventix\Resources\Exceptions\PlanConflictException;
use Konventix\Resources\Exceptions\PlanStaleException;
use Konventix\Resources\Models\PlanVersion;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceClaim;

/**
 * The save pipeline (design §6.1) and its projection (§6.2): every place in
 * the document is a `resources` row with the same id, the version row is
 * written in the same transaction, and a stale or foreign document writes
 * nothing at all.
 */
it('creates one resource per place with the element id as the resource id', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);

    $result = seatingSave($plan, seatingDocument([$a, $b]));
    $places = seatingPlaces($plan);
    $first = $places->get($a['id']);

    expect($result->version)->toBe(1)
        ->and($places)->toHaveCount(2)
        ->and($first)->not->toBeNull()
        ->and($first->ref)->toBe('A-01')
        ->and($first->label)->toBe('A-01')
        ->and($first->resource_type_id)->toBe($type->id)
        ->and($first->plan_id)->toBe($plan->id)
        ->and($first->brand_id)->toBe($brand->id)
        ->and($first->capacity)->toBe(1)
        ->and($first->is_published)->toBeFalse()
        ->and($result->changedPlaceIds)->toEqualCanonicalizing([$a['id'], $b['id']])
        ->and($plan->fresh()->version)->toBe(1)
        ->and($plan->fresh()->document->elements)->toHaveCount(2);
});

it('updates label, ref, category, blocked and accessible on the next save', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $vip = seatingCategory($eventId, 'vip');
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    $result = seatingSave($plan, seatingDocument([
        array_merge($a, ['ref' => 'V-01', 'label' => 'VIP 1', 'resourceTypeId' => $vip->id, 'blocked' => true, 'accessible' => true]),
    ]));
    $place = seatingPlaces($plan)->get($a['id']);

    expect($result->version)->toBe(2)
        ->and($result->changedPlaceIds)->toBe([$a['id']])
        ->and($place->ref)->toBe('V-01')
        ->and($place->label)->toBe('VIP 1')
        ->and($place->resource_type_id)->toBe($vip->id)
        ->and($place->blocked)->toBeTrue()
        ->and($place->accessible)->toBeTrue();
});

it('soft-deletes a removed place, keeps its row and restores it when it returns', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));

    seatingSave($plan, seatingDocument([$a]));

    expect(seatingPlaces($plan)->get($b['id'])->deleted_at)->not->toBeNull()
        ->and(seatingPlaces($plan)->get($a['id'])->deleted_at)->toBeNull()
        ->and(Resource::query()->where('plan_id', $plan->id)->count())->toBe(1);

    seatingSave($plan, seatingDocument([$a, $b]));

    expect(seatingPlaces($plan)->get($b['id'])->deleted_at)->toBeNull()
        ->and($plan->version)->toBe(3);
});

it('derives zone, group and neighbours from the document', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $zone = seatingZone();
    $group = seatingGroup(['parentId' => $zone['id'], 'x' => 1000, 'y' => 1000]);
    $p1 = seatingPlace($type->id, 'A-01', ['parentId' => $group['id'], 'x' => 0]);
    $p2 = seatingPlace($type->id, 'A-02', ['parentId' => $group['id'], 'x' => 800]);
    $p3 = seatingPlace($type->id, 'A-03', ['parentId' => $group['id'], 'x' => 1900]);
    $loose = seatingPlace($type->id, 'X-01', ['x' => 15000, 'y' => 15000]);

    seatingSave($plan, seatingDocument([$zone, $group, $p1, $p2, $p3, $loose]));
    $rows = seatingPlaces($plan);

    expect($rows->get($p1['id'])->zone_id)->toBe($zone['id'])
        ->and($rows->get($p1['id'])->group_id)->toBe($group['id'])
        ->and($rows->get($p1['id'])->neighbors)->toBe([$p2['id']])
        ->and($rows->get($p2['id'])->neighbors)->toBe([$p1['id']])
        ->and($rows->get($p3['id'])->neighbors)->toBe([])
        ->and($rows->get($loose['id'])->zone_id)->toBeNull()
        ->and($rows->get($loose['id'])->group_id)->toBeNull()
        ->and($rows->get($loose['id'])->neighbors)->toBe([]);
});

it('derives is_published from the plan on every save', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    expect(seatingPlaces($plan)->get($a['id'])->is_published)->toBeFalse();

    $plan->forceFill(['published_at' => now()])->save();
    seatingSave($plan, seatingDocument([$a]));

    expect(seatingPlaces($plan)->get($a['id'])->is_published)->toBeTrue();
});

it('refuses a stale expected version with the current version and writes nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a]));

    $stale = new PlanSaveData(expectedVersion: 0, document: seatingDocument([$a, $b]), resolutions: []);

    try {
        app(SavePlan::class)->handle($plan, $stale, null, PlanVersionSource::Editor);
        $this->fail('Expected PlanStaleException.');
    } catch (PlanStaleException $e) {
        $response = $e->render(request());

        expect($e->currentVersion)->toBe(1)
            ->and($response->getStatusCode())->toBe(409)
            ->and($response->getData(true))->toMatchArray(['currentVersion' => 1]);
    }

    expect(seatingPlaces($plan))->toHaveCount(1)
        ->and(PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->count())->toBe(1)
        ->and($plan->fresh()->version)->toBe(1);
});

it('refuses a place id that already exists under another plan or brand', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $first = seatingPlan($eventId, ['name' => 'Hall 1']);
    $second = seatingPlan($eventId, ['name' => 'Hall 2']);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($first, seatingDocument([$a]));

    try {
        seatingSave($second, seatingDocument([array_merge($a, ['ref' => 'Z-01'])]));
        $this->fail('Expected ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['document.elements.0.id']);
    }

    expect(seatingPlaces($second))->toHaveCount(0)
        ->and($second->fresh()->version)->toBe(0);

    $other = Brand::factory()->create();
    $this->withBrand($other);
    $otherEvent = seatingEvent($other);
    $otherType = seatingCategory($otherEvent);
    $foreign = seatingPlan($otherEvent);

    expect(fn () => seatingSave($foreign, seatingDocument([array_merge($a, ['resourceTypeId' => $otherType->id])])))
        ->toThrow(ValidationException::class);
    expect(seatingPlaces($foreign))->toHaveCount(0);
});

it('writes one version row per save and keeps only the last fifty', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId, ['version' => 60]);

    // Versions 11..60, as if saved earlier.
    foreach (range(11, 60) as $number) {
        $row = new PlanVersion;
        $row->forceFill([
            'brand_id' => $brand->id,
            'plan_id' => $plan->id,
            'version' => $number,
            'document' => $plan->document,
            'source' => PlanVersionSource::Editor->value,
            'created_by' => null,
            'created_at' => now(),
        ])->save();
    }

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]), actorUserId: $brand->id);

    $versions = PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->orderBy('version')->pluck('version');
    $latest = PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->where('version', 61)->firstOrFail();

    expect($versions->count())->toBe(SavePlan::VERSIONS_KEPT)
        ->and($versions->first())->toBe(12)
        ->and($versions->last())->toBe(61)
        ->and($latest->getAttributes()['source'])->toBe('editor')
        ->and($latest->getAttributes()['created_by'])->toBe($brand->id)
        ->and($latest->document->elements)->toHaveCount(1);
});

it('dispatches PlanSaved after the commit with the changed place ids', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    Event::fake([PlanSaved::class]);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    Event::assertDispatched(PlanSaved::class, fn (PlanSaved $event): bool => $event->planId === $plan->id
        && $event->eventId === $eventId
        && $event->brandId === $brand->id
        && $event->version === 1
        && $event->changedPlaceIds === [$a['id']]);
});

it('validates a document as a dry run without writing anything', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a]));

    $validation = app(ValidatePlan::class)->handle(
        $plan,
        new PlanSaveData(expectedVersion: 1, document: seatingDocument([$a, $b]), resolutions: []),
    );

    expect($validation->conflicts)->toBe([])
        ->and($validation->warnings)->toBe([])
        ->and($validation->diff->added)->toBe(1)
        ->and($validation->diff->changed)->toBe(0)
        ->and($validation->diff->removed)->toBe(0)
        ->and(seatingPlaces($plan))->toHaveCount(1)
        ->and($plan->fresh()->version)->toBe(1);

    expect(fn () => app(ValidatePlan::class)->handle(
        $plan,
        new PlanSaveData(expectedVersion: 0, document: seatingDocument([$a]), resolutions: []),
    ))->toThrow(PlanStaleException::class);
});

/**
 * The partial unique on `(plan_id, ref)` is checked per row inside the
 * upsert (a partial index cannot be DEFERRABLE), so the projection frees
 * the old refs before it writes the new ones — design §6.1 step 6.
 */
it('lets two places exchange their refs in one save', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));

    seatingSave($plan, seatingDocument([
        array_merge($a, ['ref' => 'A-02', 'label' => 'A-02']),
        array_merge($b, ['ref' => 'A-01', 'label' => 'A-01']),
    ]));

    expect(seatingPlaces($plan)->get($a['id'])->ref)->toBe('A-02')
        ->and(seatingPlaces($plan)->get($b['id'])->ref)->toBe('A-01')
        ->and($plan->fresh()->version)->toBe(2);
});

/**
 * A ref freed by a removal in the same save is free for the place that
 * takes it — the soft delete runs first and leaves the partial index.
 */
it('reuses the ref of a place removed in the same save', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));

    seatingSave($plan, seatingDocument([array_merge($b, ['ref' => 'A-01', 'label' => 'A-01'])]));

    expect(seatingPlaces($plan)->get($b['id'])->ref)->toBe('A-01')
        ->and(seatingPlaces($plan)->get($a['id'])->deleted_at)->not->toBeNull();
});

/**
 * Design §6.1 step 2: the structural invariants are enforced by the Action,
 * not only by the editor. `PlanInvariants::check()` returns the violations
 * and `SavePlan` turns them into the 422 the dialog keys by field path — a
 * place whose category belongs to no category of this event is the case the
 * editor cannot produce but an import or a stale tab can.
 */
it('refuses a place whose category is not one of the event and writes nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $foreignCategory = seatingCategory(seatingEvent($brand), 'other');
    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($foreignCategory->id, 'B-01', ['x' => 800]);

    $errors = null;

    try {
        seatingSave($plan, seatingDocument([$a, $b]));
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toHaveKey('document.elements.1.resourceTypeId')
        ->and(seatingPlaces($plan))->toHaveCount(0)
        ->and($plan->fresh()->version)->toBe(0)
        ->and(PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->count())->toBe(0);
});

/**
 * The dry run refuses exactly what the save refuses, or the editor would
 * show a green validation and then a red save (design §6.1).
 */
it('refuses the same document in the dry run', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $orphan = seatingPlace($type->id, 'A-01', ['parentId' => (string) Str::ulid()]);

    expect(fn () => app(ValidatePlan::class)->handle(
        $plan,
        new PlanSaveData(expectedVersion: 0, document: seatingDocument([$orphan]), resolutions: []),
    ))->toThrow(ValidationException::class);
});

/**
 * Design §6.1 step 4: a save that would delete, recategorise or block an
 * occupied place refuses before any lock is taken and before the
 * transaction opens at all — `guardConflicts()` throws straight out of
 * `handle()`, so nothing about the plan, its version rows or the
 * projection changes. This is the pipeline's central guarantee: a rejected
 * save costs nothing.
 */
it('refuses to remove an occupied place without a resolution and writes nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    $place = seatingPlaces($plan)->get($a['id']);
    $booking = seatingOccupy($place);

    $versionBefore = $plan->fresh()->version;
    $versionRowsBefore = PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->count();
    $placeBefore = $place->fresh()->getAttributes();

    try {
        seatingSave($plan, seatingDocument([]));
        test()->fail('Expected PlanConflictException.');
    } catch (PlanConflictException $e) {
        expect($e->conflicts)->toHaveCount(1)
            ->and($e->conflicts[0]->placeId)->toBe($a['id'])
            ->and($e->conflicts[0]->reason)->toBe(ConflictReason::Deleted);
    }

    expect($plan->fresh()->version)->toBe($versionBefore)
        ->and(PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->count())->toBe($versionRowsBefore)
        ->and($place->fresh()->getAttributes())->toBe($placeBefore)
        ->and(Resource::withoutBrandScope()->withTrashed()->where('plan_id', $plan->id)->count())->toBe(1)
        ->and($booking->fresh()->state)->toBe(BookingState::Confirmed);
});

/**
 * The other half of the same step: once the operator resolves the
 * conflict, the save applies it through `ReleaseResourceBooking` — the
 * booking goes back with reason `plan_conflict` and its claim to
 * `unplaced` (design §6.3) — inside the very transaction that projects the
 * removal, rather than refusing.
 */
it('releases the occupying booking and its claim when the conflict is resolved', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    $place = seatingPlaces($plan)->get($a['id']);
    $booking = seatingOccupy($place);

    $result = seatingSave($plan, seatingDocument([]), resolutions: [
        new PlanConflictResolutionData(placeId: $a['id']),
    ]);

    expect($result->version)->toBe(2)
        ->and($booking->fresh()->state)->toBe(BookingState::Released)
        ->and(ResourceClaim::withoutBrandScope()->whereKey($booking->claim_id)->value('state'))->toBe(ClaimState::Unplaced)
        ->and(seatingPlaces($plan)->get($a['id'])->deleted_at)->not->toBeNull();
});

it('publishes and unpublishes a plan, re-deriving is_published on its places', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $gone = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $gone]));
    seatingSave($plan, seatingDocument([$a]));

    Event::fake([PlanPublished::class, PlanUnpublished::class]);

    app(PublishPlan::class)->handle($plan);

    expect($plan->published_at)->not->toBeNull()
        ->and(seatingPlaces($plan)->get($a['id'])->is_published)->toBeTrue()
        // A removed place is history; publishing does not resurrect its flag.
        ->and(seatingPlaces($plan)->get($gone['id'])->is_published)->toBeFalse();
    Event::assertDispatched(PlanPublished::class, fn (PlanPublished $event): bool => $event->planId === $plan->id && $event->eventId === $eventId && $event->brandId === $brand->id);

    $publishedAt = $plan->published_at;
    app(PublishPlan::class)->handle($plan);

    expect($plan->published_at?->equalTo($publishedAt))->toBeTrue();
    Event::assertDispatchedTimes(PlanPublished::class, 1);

    app(UnpublishPlan::class)->handle($plan);

    expect($plan->published_at)->toBeNull()
        ->and(seatingPlaces($plan)->get($a['id'])->is_published)->toBeFalse();
    Event::assertDispatched(PlanUnpublished::class, fn (PlanUnpublished $event): bool => $event->planId === $plan->id);

    app(UnpublishPlan::class)->handle($plan);
    Event::assertDispatchedTimes(PlanUnpublished::class, 1);
});
