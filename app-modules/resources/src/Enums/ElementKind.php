<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an element of a plan document is (design §5). Additive: a new kind
 * is a new case plus its fields on `PlanElementData`, never a schema break.
 *
 * Only `Place` reaches the projection (`resources`); zones and groups are
 * document-only and survive as `zone_id`/`group_id` on the place row.
 */
#[TypeScript]
enum ElementKind: string
{
    case Zone = 'zone';
    case Group = 'group';
    case Place = 'place';
    case Fixture = 'fixture';
    case Annotation = 'annotation';

    /**
     * The word a person reads for this kind (ADR-0068): a violation message
     * names it, and the raw value would be English in every locale.
     */
    public function label(): string
    {
        return match ($this) {
            self::Zone => __('Zone'),
            self::Group => __('Group'),
            self::Place => __('Place'),
            self::Fixture => __('Fixture'),
            self::Annotation => __('Annotation'),
        };
    }

    /**
     * The parent table of design §5: zone → root or zone; group → root,
     * zone or group; place → root, zone or group; fixture → root, zone or
     * group; annotation → anywhere. `null` is the root.
     */
    public function allowsParent(?self $parent): bool
    {
        return match ($this) {
            self::Zone => $parent === null || $parent === self::Zone,
            self::Group, self::Place, self::Fixture => $parent === null
                || $parent === self::Zone
                || $parent === self::Group,
            self::Annotation => true,
        };
    }
}
