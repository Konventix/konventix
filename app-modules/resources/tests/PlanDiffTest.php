<?php

declare(strict_types=1);

use Konventix\Resources\Plans\PlanDiff;

/**
 * The read-only diff of a document against the projection (design §6.1
 * step 3): which places are new, which changed one of the projected
 * columns, which are gone, and which ids belong to somebody else. Geometry
 * never counts as a change (§6.3: moving a place is never a conflict).
 */
it('classifies added, changed and removed places against the projection', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));

    $c = seatingPlace($type->id, 'A-03', ['x' => 1600]);
    $diff = app(PlanDiff::class)->compute($plan, seatingDocument([
        array_merge($a, ['label' => 'Front row 1']),
        $c,
    ]));

    expect($diff->added)->toBe([$c['id']])
        ->and($diff->changed)->toBe([$a['id'] => ['label']])
        ->and($diff->removed)->toBe([$b['id']])
        ->and($diff->foreign)->toBe([])
        ->and($diff->changedPlaceIds())->toBe(collect([$a['id'], $b['id'], $c['id']])->sort()->values()->all())
        ->and($diff->affectedIds())->toEqualCanonicalizing([$a['id'], $b['id']])
        ->and($diff->summary()->added)->toBe(1)
        ->and($diff->summary()->changed)->toBe(1)
        ->and($diff->summary()->removed)->toBe(1);
});

it('ignores geometry and reports only projected columns', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $vip = seatingCategory($eventId, 'vip');
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    $moved = array_merge($a, ['x' => 5000, 'y' => 2000, 'rotation' => 45, 'shape' => ['type' => 'rect', 'w' => 500, 'h' => 500]]);
    expect(app(PlanDiff::class)->compute($plan, seatingDocument([$moved]))->changed)->toBe([]);

    $retyped = array_merge($a, ['resourceTypeId' => $vip->id, 'blocked' => true, 'accessible' => true, 'ref' => 'V-01']);
    expect(app(PlanDiff::class)->compute($plan, seatingDocument([$retyped]))->changed)
        ->toBe([$a['id'] => ['ref', 'resource_type_id', 'blocked', 'accessible']]);
});

it('reports a new zone or group as a changed field', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    $zone = seatingZone();
    $group = seatingGroup(['parentId' => $zone['id']]);
    $diff = app(PlanDiff::class)->compute($plan, seatingDocument([$zone, $group, array_merge($a, ['parentId' => $group['id']])]));

    expect($diff->changed)->toBe([$a['id'] => ['zone_id', 'group_id']])
        ->and($diff->rows[$a['id']]['zone_id'])->toBe($zone['id'])
        ->and($diff->rows[$a['id']]['group_id'])->toBe($group['id']);
});

it('flags an id that lives under another plan as foreign', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $first = seatingPlan($eventId, ['name' => 'Hall 1']);
    $second = seatingPlan($eventId, ['name' => 'Hall 2']);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($first, seatingDocument([$a]));

    $diff = app(PlanDiff::class)->compute($second, seatingDocument([$a]));

    expect($diff->foreign)->toBe([$a['id']])
        ->and($diff->foreignErrors(seatingDocument([$a])))->toBe([
            'document.elements.0.id' => ['This place id already exists under another plan or brand.'],
        ]);
});

it('treats a soft-deleted id that comes back as added', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800]);
    seatingSave($plan, seatingDocument([$a, $b]));
    seatingSave($plan, seatingDocument([$a]));

    $diff = app(PlanDiff::class)->compute($plan, seatingDocument([$a, $b]));

    expect($diff->added)->toBe([$b['id']])
        ->and($diff->removed)->toBe([]);
});
