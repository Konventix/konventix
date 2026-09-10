<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanElementData;
use Konventix\Resources\Data\PlanWarningData;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Enums\PlanWarningCode;

/**
 * Non-blocking findings (design §6.1 step 5): places that overlap inside the
 * same zone, and places outside every zone. Both are computed on world
 * boxes, so a rotated group is judged where it actually lies.
 *
 * A plan without zones gets no "outside" warnings — every place would be one,
 * and a first sketch without zones is a normal state of a plan.
 */
final class PlanWarnings
{
    /**
     * @return list<PlanWarningData>
     */
    public static function collect(PlanDocumentData $document): array
    {
        $geometry = new PlanGeometry($document);
        $zones = $geometry->ofKind(ElementKind::Zone);
        $places = $geometry->ofKind(ElementKind::Place);
        $warnings = [];

        /** @var array<string, list<PlanElementData>> $byZone */
        $byZone = [];

        foreach ($places as $place) {
            $zone = $geometry->ancestorOfKind($place->id, ElementKind::Zone);

            if ($zone !== null) {
                $byZone[$zone->id][] = $place;
            }
        }

        foreach ($byZone as $members) {
            $count = count($members);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $members[$i];
                    $b = $members[$j];

                    if ($geometry->worldBox($a->id)->overlaps($geometry->worldBox($b->id))) {
                        $warnings[] = new PlanWarningData(
                            code: PlanWarningCode::Overlap,
                            placeIds: [$a->id, $b->id],
                            message: sprintf('Places %s and %s overlap.', self::name($a), self::name($b)),
                        );
                    }
                }
            }
        }

        if ($zones === []) {
            return $warnings;
        }

        $zoneBoxes = array_map(fn (PlanElementData $zone): Box => $geometry->worldBox($zone->id), $zones);

        foreach ($places as $place) {
            $box = $geometry->worldBox($place->id);
            $inside = false;

            foreach ($zoneBoxes as $zoneBox) {
                if ($zoneBox->contains($box)) {
                    $inside = true;
                    break;
                }
            }

            if (! $inside) {
                $warnings[] = new PlanWarningData(
                    code: PlanWarningCode::OutsideZone,
                    placeIds: [$place->id],
                    message: sprintf('Place %s lies outside every zone.', self::name($place)),
                );
            }
        }

        return $warnings;
    }

    private static function name(PlanElementData $element): string
    {
        return $element->ref ?? $element->label ?? $element->id;
    }
}
