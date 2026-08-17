<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Konventix\Platform\Blocks\Types\HeroBlock;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stored payload of the built-in `hero` block: a large opening heading with
 * an optional subheading, call-to-action link, and illustration image.
 *
 * The image is a **media-library ULID** (ADR-0035), never a URL: a stored
 * URL rots the moment the media disk or asset host changes, which ADR-0043
 * makes a routine deployment event rather than a migration. The URL the Vue
 * component receives is resolved at render time by
 * {@see HeroBlock::resolve()} into {@see HeroResolvedData}.
 */
#[TypeScript]
class HeroBlockData extends Data
{
    public function __construct(
        public string $heading,
        public ?string $subheading,
        public ?string $ctaLabel,
        public ?string $ctaUrl,
        /** Media-library ULID (ADR-0035), not a URL. */
        #[Nullable, Ulid]
        public ?string $imageMediaId,
    ) {}
}
