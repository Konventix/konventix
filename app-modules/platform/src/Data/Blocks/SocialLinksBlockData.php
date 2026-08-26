<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Payload of the `social-links` block: a small set of social/profile links,
 * meant for a footer column.
 */
#[TypeScript]
final class SocialLinksBlockData extends Data
{
    /**
     * @param  array<int, SocialLinkData>  $links
     */
    public function __construct(
        #[Present, ArrayType, Max(10), DataCollectionOf(SocialLinkData::class)]
        public readonly array $links,
    ) {}
}
