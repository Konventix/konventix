<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanElementData;
use Konventix\Resources\Enums\ElementKind;

/**
 * Which places sit next to which, computed on every save into
 * `resources.neighbors` (design §4.2, unused in v1 — group booking and
 * "seat me next to" are the §14 follow-ups that will read it).
 *
 * Two places are neighbours when they share a group and their world boxes
 * touch or sit within a quarter of that group's layout spacing of each
 * other. Places without a group have no neighbours: a loose chair next to a
 * table row is not part of the row.
 */
final class PlanNeighbors
{
    private const float SPACING_FRACTION = 0.25;

    /**
     * @return array<string, list<string>> place id => sorted neighbour ids, one entry per place
     */
    public static function compute(PlanDocumentData $document): array
    {
        $geometry = new PlanGeometry($document);

        /** @var array<string, list<PlanElementData>> $byGroup */
        $byGroup = [];
        /** @var array<string, float> $tolerance */
        $tolerance = [];
        $result = [];

        foreach ($geometry->ofKind(ElementKind::Place) as $place) {
            $result[$place->id] = [];
            $group = $geometry->ancestorOfKind($place->id, ElementKind::Group);

            if ($group === null) {
                continue;
            }

            $byGroup[$group->id][] = $place;
            // `layout` (`PlanElementData::$layout`) is genuinely nullable,
            // but `??`'s left operand is evaluated with isset() semantics
            // across the whole chain, so a null `layout` never reaches a
            // property read on null — confirmed with a standalone repro
            // under a custom E_ALL error handler, no warning either way.
            // `?->` here is therefore what PHPStan's nullsafe.neverNull
            // flags it as: redundant ahead of `?? 0`.
            $tolerance[$group->id] = ($group->layout->spacing ?? 0) * self::SPACING_FRACTION;
        }

        foreach ($byGroup as $groupId => $places) {
            $count = count($places);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $places[$i];
                    $b = $places[$j];

                    if ($geometry->worldBox($a->id)->intersects($geometry->worldBox($b->id), $tolerance[$groupId])) {
                        $result[$a->id][] = $b->id;
                        $result[$b->id][] = $a->id;
                    }
                }
            }
        }

        foreach ($result as $id => $ids) {
            sort($ids, SORT_STRING);
            $result[$id] = array_values(array_unique($ids));
        }

        return $result;
    }
}
