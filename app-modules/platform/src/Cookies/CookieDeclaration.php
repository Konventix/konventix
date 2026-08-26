<?php

declare(strict_types=1);

namespace Konventix\Platform\Cookies;

use Konventix\Platform\Enums\CookieCategory;

/**
 * One cookie a module sets on the visitor's device, declared for the
 * cookie-settings dialog.
 */
final readonly class CookieDeclaration
{
    public function __construct(
        public string $name,
        public string $purpose,
        public CookieCategory $category,
        /** Human text: "session", "1 year", "7 days". */
        public string $lifetime,
        public string $module,
    ) {}
}
