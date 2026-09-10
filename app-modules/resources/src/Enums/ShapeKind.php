<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The geometry of an element (design §5). For `Rect` and `Polygon` the
 * element's `x, y` is the top-left corner; for `Ellipse` and `Point` it is
 * the centre. `Arc` is deliberately absent (design §14).
 */
#[TypeScript]
enum ShapeKind: string
{
    case Point = 'point';
    case Rect = 'rect';
    case Ellipse = 'ellipse';
    case Polygon = 'polygon';
}
