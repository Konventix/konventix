<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A fixture is drawn, never booked (design §5). The type only picks a
 * default shape and a fill in the editor.
 */
#[TypeScript]
enum FixtureType: string
{
    case Table = 'table';
    case Counter = 'counter';
    case Stage = 'stage';
    case Wall = 'wall';
    case Pillar = 'pillar';
    case Custom = 'custom';
}
