<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The cookie-settings dialog's one write: whether functional storage is
 * allowed.
 */
#[TypeScript]
final class CookiePreferencesData extends Data
{
    public function __construct(
        #[BooleanType]
        public readonly bool $functional,
    ) {}
}
