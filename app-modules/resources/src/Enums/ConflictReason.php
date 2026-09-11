<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why a save conflicts with an occupying booking (design §6.3). Moving a
 * place or changing its label, shape or ref is never a conflict.
 */
#[TypeScript]
enum ConflictReason: string
{
    case Deleted = 'deleted';
    case CategoryChanged = 'category_changed';
    case Blocked = 'blocked';
}
