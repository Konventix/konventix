<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Server-resolved props for the `hero` block's Vue component: the stored
 * payload with its media ULID turned into a URL and the library's alt text
 * (ADR-0035 — `MediaLibrary` is the only sanctioned ULID→asset path).
 *
 * A ULID that no longer resolves yields `imageUrl: null`, so a stale
 * reference renders as a hero without an image rather than failing the
 * page — the same graceful degradation the library itself applies.
 */
#[TypeScript]
final class HeroResolvedData extends Data
{
    public function __construct(
        public readonly string $heading,
        public readonly ?string $subheading,
        public readonly ?string $ctaLabel,
        public readonly ?string $ctaUrl,
        public readonly ?string $imageUrl,
        /** The media item's own alt text; the component falls back to the heading. */
        public readonly ?string $imageAlt,
    ) {}
}
