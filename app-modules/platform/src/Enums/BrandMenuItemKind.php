<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a brand menu entry is.
 *
 * `Page` links one of the brand's own static pages and inherits its
 * visibility; `Url` is a free destination under the same whitelist the
 * footer uses; `Parent` is a heading that groups the entries beneath it and
 * navigates nowhere itself. One level only: a parent never sits under a
 * parent (`UpdateBrandMenu` refuses it).
 */
#[TypeScript]
enum BrandMenuItemKind: string
{
    case Page = 'page';
    case Url = 'url';
    case Parent = 'parent';
}
