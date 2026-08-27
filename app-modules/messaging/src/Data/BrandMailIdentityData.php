<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The brand identity the branded mail layout renders (ADR-0048 point 4):
 * logo and accent colour, the operator's legal line, and the brand's
 * imprint/privacy footer links. Resolved per send by
 * `Branding\BrandMailIdentityResolver` — never held across sends, since a
 * worker renders mail for many brands over its lifetime (Octane).
 *
 * `#[TypeScript]` because the template-preview screen (a later task) renders
 * the same layout client-side against this shape.
 */
#[TypeScript]
final class BrandMailIdentityData extends Data
{
    public function __construct(
        public readonly string $brandId,
        public readonly string $brandName,
        public readonly ?string $logoUrl,
        public readonly ?string $logoAlt,
        public readonly string $accentColor,
        public readonly string $accentTextColor,
        public readonly string $sellerLine,
        public readonly ?string $imprintUrl,
        public readonly ?string $privacyUrl,
    ) {}
}
