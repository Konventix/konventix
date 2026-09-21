<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Enums\PageVisibility;
use Konventix\Platform\Models\StaticPage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the brand's pages as the menu editor's page picker lists it
 * (ADR-0009): enough to name it, to fall back on its title as the entry's
 * label, and to show the visibility the entry will inherit.
 */
#[TypeScript]
final class BrandMenuPageOptionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly bool $published,
        public readonly PageVisibility $visibility,
    ) {}

    public static function ofModel(StaticPage $page): self
    {
        return new self(
            id: $page->getKey(),
            title: $page->title,
            slug: $page->slug,
            published: $page->published_at !== null,
            visibility: $page->visibility,
        );
    }
}
