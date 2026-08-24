<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The visual identity the shell renders for whoever the request resolved to
 * (ADR-0009): a brand on a brand host, the operator on the admin host.
 *
 * Every URL is nullable and every consumer must have a fallback for null —
 * a brand that has uploaded nothing is the normal state, not an error, and
 * the bundled Konventix mark stands in. That is deliberately the only
 * fallback level: there are no operator-wide defaults, because media items
 * are brand-owned (ADR-0035) and the operator owns no brand.
 */
#[TypeScript]
final class BrandIdentityData extends Data
{
    public function __construct(
        /** The display name shown beside the mark and in the document title. */
        public string $name,
        public ?string $logoUrl,
        public ?string $logoDarkUrl,
        /** The media item's own alt text; consumers fall back to the name. */
        public ?string $logoAlt,
        public ?string $faviconUrl,
    ) {}
}
