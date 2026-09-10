<?php

declare(strict_types=1);

use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Plans\PlanCanonicalJson;

/**
 * Canonical form (design §6.4): keys sorted, elements ordered by id, whole
 * floats written as integers, one trailing newline — so two exports of the
 * same plan are byte-identical and diffable.
 */
it('sorts keys, orders elements by id and writes whole floats as integers', function (): void {
    $a = seatingPlace('type', 'A-01', ['rotation' => 0]);
    $b = seatingPlace('type', 'A-02', ['x' => 800, 'rotation' => 90]);

    $json = PlanCanonicalJson::encode(seatingDocument([$b, $a]));
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $keys = array_keys($decoded['elements'][0]);
    $sorted = $keys;
    sort($sorted, SORT_STRING);

    expect(array_keys($decoded))->toBe(['elements', 'schema'])
        ->and($decoded['elements'][0]['id'])->toBe($a['id'])
        ->and($decoded['elements'][1]['id'])->toBe($b['id'])
        ->and($keys)->toBe($sorted)
        ->and($decoded['elements'][0]['rotation'])->toBeInt()
        ->and($decoded['elements'][1]['rotation'])->toBe(90)
        ->and($json)->toEndWith("\n");
});

it('is stable across element order', function (): void {
    $a = seatingPlace('type', 'A-01');
    $b = seatingPlace('type', 'A-02', ['x' => 800]);

    expect(PlanCanonicalJson::encode(seatingDocument([$a, $b])))
        ->toBe(PlanCanonicalJson::encode(seatingDocument([$b, $a])));
});

it('decodes into a document that re-encodes identically', function (): void {
    $zone = seatingZone();
    $a = seatingPlace('type', 'A-01', ['parentId' => $zone['id']]);

    $json = PlanCanonicalJson::encode(seatingDocument([$zone, $a]));
    $again = PlanCanonicalJson::encode(PlanDocumentData::from(PlanCanonicalJson::decode($json)));

    expect($again)->toBe($json);
});
