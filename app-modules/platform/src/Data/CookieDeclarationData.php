<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Cookies\CookieDeclaration;
use Konventix\Platform\Enums\CookieCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One declared cookie, as the cookie-settings dialog renders it.
 */
#[TypeScript]
final class CookieDeclarationData extends Data
{
    public function __construct(
        public string $name,
        public string $purpose,
        public CookieCategory $category,
        public string $lifetime,
        public string $module,
    ) {}

    public static function ofDeclaration(CookieDeclaration $declaration): self
    {
        return new self(
            name: $declaration->name,
            purpose: __($declaration->purpose),
            category: $declaration->category,
            lifetime: __($declaration->lifetime),
            module: $declaration->module,
        );
    }
}
