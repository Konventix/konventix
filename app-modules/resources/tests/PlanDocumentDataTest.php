<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Plans\PlanInvariants;

/**
 * The plan document has two guards (design §6.1 steps 1 and 2). Shape —
 * known kinds, integer millimetres, point pairs — is laravel-data
 * validation and answers with a ValidationException. Structure — parents,
 * cycles, refs, categories, polygon size — is `PlanInvariants::check()`,
 * pure PHP over an already-built document, answering with a list keyed the
 * way SavePlan reports it: `document.elements.<index>.<field>`.
 */
it('accepts a valid document', function (): void {
    $typeId = (string) Str::ulid();
    $group = seatingRawElement('group', ['ref' => 'A', 'label' => 'A']);
    $place = seatingRawPlace($typeId, ['parentId' => $group['id'], 'ref' => 'A-01', 'label' => 'A-01']);

    $document = PlanDocumentData::validateAndCreate(seatingRawDocument([$group, $place]));

    expect($document->schema)->toBe(PlanDocumentData::SCHEMA)
        ->and($document->elements)->toHaveCount(2)
        ->and($document->elements[1]->resourceTypeId)->toBe($typeId)
        ->and($document->elements[1]->shape->w)->toBe(800)
        ->and(PlanInvariants::check($document, [$typeId => CapacityMode::Unit]))->toBe([]);
});

it('rejects an unknown element kind', function (): void {
    expect(fn () => PlanDocumentData::validateAndCreate(seatingRawDocument([seatingRawElement('marker')])))
        ->toThrow(ValidationException::class);
});

it('rejects a non-integer millimetre coordinate', function (): void {
    expect(fn () => PlanDocumentData::validateAndCreate(seatingRawDocument([seatingRawElement('zone', ['x' => 12.5])])))
        ->toThrow(ValidationException::class);
});

it('rejects a polygon point that is not a pair', function (): void {
    $fixture = seatingRawElement('fixture', [
        'fixtureType' => 'wall',
        'shape' => ['type' => 'polygon', 'w' => null, 'h' => null, 'radius' => null, 'rx' => null, 'ry' => null, 'points' => [[0, 0], [1000], [1000, 1000]]],
    ]);

    expect(fn () => PlanDocumentData::validateAndCreate(seatingRawDocument([$fixture])))
        ->toThrow(ValidationException::class);
});

it('rejects a schema it does not speak', function (): void {
    $document = seatingRawDocument([]);
    $document['schema'] = 'konventix.plan/v2';

    expect(fn () => PlanDocumentData::validateAndCreate($document))->toThrow(ValidationException::class);
});

it('reports a forbidden parent', function (): void {
    $group = seatingRawElement('group');
    $zone = seatingRawElement('zone', ['parentId' => $group['id']]);

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$group, $zone])), []);

    expect($violations)->toBe([
        ['path' => 'document.elements.1.parentId', 'message' => 'A zone may not be placed inside a group.'],
    ]);
});

it('reports a missing parent', function (): void {
    $place = seatingRawPlace((string) Str::ulid(), ['parentId' => (string) Str::ulid()]);

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$place])), [$place['resourceTypeId'] => CapacityMode::Unit]);

    expect($violations)->toBe([
        ['path' => 'document.elements.0.parentId', 'message' => 'Parent element does not exist.'],
    ]);
});

it('reports a cycle', function (): void {
    $a = seatingRawElement('group');
    $b = seatingRawElement('group', ['parentId' => $a['id']]);
    $a['parentId'] = $b['id'];

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$a, $b])), []);

    expect(array_column($violations, 'path'))->toBe(['document.elements.0.parentId', 'document.elements.1.parentId'])
        ->and(array_unique(array_column($violations, 'message')))->toBe(['Element is its own ancestor.']);
});

it('reports a duplicate id', function (): void {
    $zone = seatingRawElement('zone');
    $twin = seatingRawElement('zone', ['id' => $zone['id']]);

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$zone, $twin])), []);

    expect($violations)->toBe([
        ['path' => 'document.elements.1.id', 'message' => 'Element id is used more than once.'],
    ]);
});

it('reports a duplicate ref', function (): void {
    $typeId = (string) Str::ulid();
    $first = seatingRawPlace($typeId, ['ref' => 'A-01']);
    $second = seatingRawPlace($typeId, ['ref' => 'A-01']);

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$first, $second])), [$typeId => CapacityMode::Unit]);

    expect($violations)->toBe([
        ['path' => 'document.elements.1.ref', 'message' => 'Ref is used more than once.'],
    ]);
});

it('reports a place without a category', function (): void {
    $place = seatingRawElement('place');

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$place])), []);

    expect($violations)->toBe([
        ['path' => 'document.elements.0.resourceTypeId', 'message' => 'A place needs a category.'],
    ]);
});

it('reports a category that does not belong to the plan', function (): void {
    $place = seatingRawPlace((string) Str::ulid());

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$place])), [(string) Str::ulid() => CapacityMode::Unit]);

    expect($violations)->toBe([
        ['path' => 'document.elements.0.resourceTypeId', 'message' => "Category does not belong to this plan's event."],
    ]);
});

/**
 * Item 6 of the 2026-09-10 fix wave (#278): nothing required a
 * plan-placed resource's category to be unit mode. A quantity-mode
 * category never arms the exclusion constraint (ADR-0020), so the seat
 * invariant would survive only through the summed guard ADR-0034 already
 * calls weaker.
 */
it('reports a place whose category is not unit mode', function (): void {
    $typeId = (string) Str::ulid();
    $place = seatingRawPlace($typeId);

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$place])), [$typeId => CapacityMode::Quantity]);

    expect($violations)->toBe([
        ['path' => 'document.elements.0.resourceTypeId', 'message' => 'Category must be in unit mode to hold a place.'],
    ]);
});

it('reports a polygon with two points', function (): void {
    $fixture = seatingRawElement('fixture', [
        'fixtureType' => 'wall',
        'shape' => ['type' => 'polygon', 'w' => null, 'h' => null, 'radius' => null, 'rx' => null, 'ry' => null, 'points' => [[0, 0], [1000, 0]]],
    ]);

    $violations = PlanInvariants::check(PlanDocumentData::from(seatingRawDocument([$fixture])), []);

    expect($violations)->toBe([
        ['path' => 'document.elements.0.shape.points', 'message' => 'A polygon needs at least three points.'],
    ]);
});
