<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\ShapeData;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Enums\ShapeKind;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;

/**
 * The plan, version and claim models of ADR-0060 and the two extended ones.
 * A constraint violation aborts the surrounding Postgres transaction
 * (.ai/rules/tests.md), so every one here happens inside its own
 * `DB::transaction` — a savepoint under RefreshDatabase — before the next
 * assertion queries again.
 */
it('round-trips the document through the cast', function (): void {
    $this->withBrand();

    $plan = Plan::factory()->withGrid(2, 3)->create();

    $fresh = Plan::query()->findOrFail($plan->id);

    expect($fresh->document)->toBeInstanceOf(PlanDocumentData::class)
        ->and($fresh->document->schema)->toBe(PlanDocumentData::SCHEMA)
        ->and($fresh->document->elements)->toHaveCount(8)
        ->and($fresh->document->elements[0]->kind)->toBe(ElementKind::Group)
        ->and($fresh->document->elements[1]->kind)->toBe(ElementKind::Place)
        ->and($fresh->document->elements[1]->parentId)->toBe($fresh->document->elements[0]->id)
        ->and($fresh->document->elements[1]->ref)->toBe('A-01')
        ->and($fresh->document->elements[1]->shape->type)->toBe(ShapeKind::Rect)
        ->and($fresh->document->elements[1]->shape->w)->toBe(800)
        ->and($fresh->background)->toBeNull()
        ->and($fresh->version)->toBe(0)
        ->and($fresh->isPublished())->toBeFalse();
});

it('starts with an empty document when none is given', function (): void {
    $this->withBrand();

    $plan = Plan::query()->create([
        'event_id' => (string) Str::ulid(),
        'name' => 'Hall 1',
        'width_mm' => 20000,
        'height_mm' => 10000,
    ]);

    expect($plan->fresh()?->document->elements)->toBe([]);
});

it('scopes plans to the ambient brand', function (): void {
    $this->withBrand();
    $plan = Plan::factory()->create();

    $this->withBrand(Brand::factory()->create());

    expect(Plan::query()->whereKey($plan->id)->exists())->toBeFalse()
        ->and(Plan::withoutBrandScope()->whereKey($plan->id)->exists())->toBeTrue();
});

it('builds a grid of 160 places with unique refs', function (): void {
    $this->withBrand();

    $plan = Plan::factory()->withGrid(10, 16)->create();

    $places = array_values(array_filter(
        $plan->document->elements,
        fn ($element): bool => $element->kind === ElementKind::Place,
    ));
    $refs = array_map(fn ($place): ?string => $place->ref, $places);

    expect($places)->toHaveCount(160)
        ->and(count(array_unique($refs)))->toBe(160)
        ->and($refs[0])->toBe('A-01')
        ->and($refs[159])->toBe('J-16')
        ->and(array_unique(array_map(fn ($place): ?string => $place->resourceTypeId, $places)))->toHaveCount(1)
        ->and(ResourceType::query()->whereKey($places[0]->resourceTypeId)->where('event_id', $plan->event_id)->exists())->toBeTrue();
});

it('casts the nullable default shape of a category', function (): void {
    $this->withBrand();

    $bare = ResourceType::factory()->create(['default_shape' => null]);
    $shaped = ResourceType::factory()->create(['default_shape' => new ShapeData(type: ShapeKind::Ellipse, radius: 250)]);

    expect($bare->fresh()?->default_shape)->toBeNull()
        ->and($shaped->fresh()?->default_shape?->type)->toBe(ShapeKind::Ellipse)
        ->and($shaped->fresh()?->default_shape?->radius)->toBe(250);
});

it('lists only published, unblocked, undeleted places as bookable', function (): void {
    $this->withBrand();

    $plan = Plan::factory()->published()->create();
    $free = Resource::factory()->forPlan($plan, 'A-01')->create();
    Resource::factory()->forPlan($plan, 'A-02')->create(['blocked' => true]);
    Resource::factory()->forPlan($plan, 'A-03')->create()->delete();
    Resource::factory()->create(['is_published' => false]);

    expect(Resource::query()->bookable()->pluck('id')->all())->toBe([$free->id])
        ->and(Resource::withTrashed()->where('plan_id', $plan->id)->count())->toBe(3)
        ->and($plan->places()->count())->toBe(2);
});

it('refuses a second live place with the same ref and allows it after a soft delete', function (): void {
    $this->withBrand();

    $plan = Plan::factory()->create();
    $first = Resource::factory()->forPlan($plan, 'A-01')->create();

    expect(fn () => DB::transaction(fn () => Resource::factory()->forPlan($plan, 'A-01')->create()))
        ->toThrow(QueryException::class);

    $first->delete();

    $again = Resource::factory()->forPlan($plan, 'A-01')->create();

    expect($again->exists)->toBeTrue()
        ->and(Resource::withTrashed()->where('plan_id', $plan->id)->where('ref', 'A-01')->count())->toBe(2);
});

it('refuses a second claim for the same registration without an amendment', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    ResourceClaim::factory()->forRegistration($registrationId)->create();

    expect(fn () => DB::transaction(fn () => ResourceClaim::factory()->forRegistration($registrationId)->create()))
        ->toThrow(QueryException::class);

    ResourceClaim::factory()->forRegistration($registrationId)->create(['amendment_id' => (string) Str::ulid()]);

    expect(ResourceClaim::query()->forRegistration($registrationId)->count())->toBe(2);
});

it('finds the unplaced claims of a registration', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $unplaced = ResourceClaim::factory()->forRegistration($registrationId)->create();
    ResourceClaim::factory()->forRegistration($registrationId)->placed()->create(['amendment_id' => (string) Str::ulid()]);
    ResourceClaim::factory()->create();

    expect(ResourceClaim::query()->forRegistration($registrationId)->unplaced()->pluck('id')->all())->toBe([$unplaced->id])
        ->and($unplaced->state)->toBe(ClaimState::Unplaced)
        ->and($unplaced->resource_type_ids)->toBeArray();
});

it('is typed for the frontend', function (): void {
    $generated = file_get_contents(base_path('resources/js/types/generated.d.ts'));
    // Prettier (printWidth 80) wraps the union onto its own line at this
    // namespace depth, so the check collapses whitespace rather than
    // asserting a fragile single-line layout.
    $normalized = preg_replace('/\s+/', ' ', $generated);

    expect($generated)->toContain('export type PlanDocumentData')
        ->toContain('export type PlanElementData')
        ->toContain('export type SeatPickerData')
        ->and($normalized)->toContain("export type ElementKind = 'zone' | 'group' | 'place' | 'fixture' | 'annotation'");
});
