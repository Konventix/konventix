<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed payload for `identity.welcome` (ADR-0009, ADR-0048 point 5), sent by
 * `Listeners\SendWelcomeOnVerified` the moment an account's first email is
 * confirmed. `shopUrl` is set only when the brand has switched
 * `commerce:shop` on (via Pennant's `Feature::for($brand)`, never a Commerce
 * import — ADR-0004); the template's shop paragraph is guarded on it.
 */
#[TypeScript]
final class WelcomeMailData extends Data
{
    public function __construct(
        public readonly string $brandName,
        public readonly string $homeUrl,
        public readonly ?string $shopUrl = null,
    ) {}
}
