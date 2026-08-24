<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a footer entry does when it is activated.
 *
 * `Dialog` exists so the footer can carry entries that open something in the
 * page instead of navigating — cookie settings being the one everybody
 * expects there. The footer resolves the key through the module component
 * registry, the same seam the notification bell uses, so an entry whose
 * dialog nothing ships yet simply does not render rather than leading
 * somewhere broken.
 */
#[TypeScript]
enum FooterLinkKind: string
{
    case Url = 'url';
    case Dialog = 'dialog';
}
