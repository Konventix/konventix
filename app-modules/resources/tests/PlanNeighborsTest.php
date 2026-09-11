<?php

declare(strict_types=1);

use Konventix\Resources\Plans\PlanNeighbors;

/**
 * Neighbours are two places of the same group whose world boxes touch or sit
 * within a quarter of the group's spacing (design §6.1: `neighbors`, computed
 * on save, unused in v1). Ids are minted in order, and `Str::ulid()` is
 * monotonic, so `p1 < p2 < p3` lexicographically — the lists come back sorted.
 */
it('links places whose boxes touch or sit within a quarter of the group spacing', function (): void {
    $group = seatingGroup(['x' => 1000, 'y' => 1000]);
    $p1 = seatingPlace('type', 'A-01', ['parentId' => $group['id'], 'x' => 0]);
    $p2 = seatingPlace('type', 'A-02', ['parentId' => $group['id'], 'x' => 800]);
    $p3 = seatingPlace('type', 'A-03', ['parentId' => $group['id'], 'x' => 1700]);
    $p4 = seatingPlace('type', 'A-04', ['parentId' => $group['id'], 'x' => 2700]);
    $p5 = seatingPlace('type', 'A-05', ['parentId' => $group['id'], 'x' => 3800]);

    $neighbors = PlanNeighbors::compute(seatingDocument([$group, $p1, $p2, $p3, $p4, $p5]));

    expect($neighbors[$p1['id']])->toBe([$p2['id']])
        ->and($neighbors[$p2['id']])->toBe([$p1['id'], $p3['id']])
        // 100 mm gap: inside the 200 mm tolerance.
        ->and($neighbors[$p3['id']])->toBe([$p2['id'], $p4['id']])
        // exactly 200 mm: still a neighbour.
        ->and($neighbors[$p4['id']])->toBe([$p3['id']])
        // 300 mm: not.
        ->and($neighbors[$p5['id']])->toBe([]);
});

it('never links places of different groups', function (): void {
    $left = seatingGroup(['x' => 0, 'y' => 0]);
    $right = seatingGroup(['x' => 800, 'y' => 0, 'label' => 'B']);
    $a = seatingPlace('type', 'A-01', ['parentId' => $left['id'], 'x' => 0]);
    $b = seatingPlace('type', 'B-01', ['parentId' => $right['id'], 'x' => 0]);

    $neighbors = PlanNeighbors::compute(seatingDocument([$left, $right, $a, $b]));

    expect($neighbors[$a['id']])->toBe([])
        ->and($neighbors[$b['id']])->toBe([]);
});

it('works in world coordinates, so a rotated group keeps its neighbours', function (): void {
    $group = seatingGroup(['x' => 5000, 'y' => 5000, 'rotation' => 90]);
    $a = seatingPlace('type', 'A-01', ['parentId' => $group['id'], 'x' => 0]);
    $b = seatingPlace('type', 'A-02', ['parentId' => $group['id'], 'x' => 800]);
    $c = seatingPlace('type', 'A-03', ['parentId' => $group['id'], 'x' => 2400]);

    $neighbors = PlanNeighbors::compute(seatingDocument([$group, $a, $b, $c]));

    expect($neighbors[$a['id']])->toBe([$b['id']])
        ->and($neighbors[$c['id']])->toBe([]);
});

it('gives a place without a group no neighbours', function (): void {
    $a = seatingPlace('type', 'X-01', ['x' => 0]);
    $b = seatingPlace('type', 'X-02', ['x' => 800]);

    $neighbors = PlanNeighbors::compute(seatingDocument([$a, $b]));

    expect($neighbors)->toBe([$a['id'] => [], $b['id'] => []]);
});
