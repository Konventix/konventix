<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Data\Blocks\BlockData;
use Konventix\Platform\Models\StaticPage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the composer page receives: a page's fields plus its stored block
 * composition, ready for the editor to hydrate (ADR-0009).
 */
#[TypeScript]
final class StaticPageData extends Data
{
    /**
     * @param  array<int, BlockData>  $blocks
     */
    public function __construct(
        public readonly string $id,
        public readonly string $brandId,
        public readonly string $brandName,
        public readonly string $title,
        public readonly string $slug,
        public readonly bool $published,
        public readonly ?string $publicUrl,
        public readonly array $blocks,
    ) {}

    public static function ofModel(StaticPage $page, string $brandName, ?string $publicUrl): self
    {
        return new self(
            id: $page->getKey(),
            brandId: $page->brand_id,
            brandName: $brandName,
            title: $page->title,
            slug: $page->slug,
            published: $page->published_at !== null,
            publicUrl: $publicUrl,
            blocks: $page->blocks?->items() ?? [],
        );
    }
}
