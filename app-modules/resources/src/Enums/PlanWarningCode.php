<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Non-blocking findings of a plan save (design §6.1 step 5): two places
 * overlap inside a zone, or a place lies outside every zone. `PlanWarnings`
 * (S2, Task 14) produces them, `PlanWarningData::$code` carries them and the
 * editor's validation panel (S7, Task 56) groups by the string value.
 */
#[TypeScript]
enum PlanWarningCode: string
{
    case Overlap = 'overlap';
    case OutsideZone = 'outside_zone';
}
