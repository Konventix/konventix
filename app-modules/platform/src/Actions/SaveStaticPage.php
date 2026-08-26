<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\ValidatesBlockComposition;
use Konventix\Platform\Data\Blocks\BlockData;
use Konventix\Platform\Data\UpdateStaticPageData;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\StaticPage;

/**
 * Saves a page's fields and block composition (ROADMAP slice 21a). Blocks are
 * validated per type through {@see ValidatesBlockComposition} with the
 * page's brand in context, media references are checked against that brand's
 * library, and usages are diffed so a dropped image stops being referenced
 * (ADR-0035).
 */
final class SaveStaticPage
{
    use ValidatesBlockComposition;

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly MediaLibrary $media,
    ) {}

    public function handle(StaticPage $page, UpdateStaticPageData $data): StaticPage
    {
        $brand = Brand::query()->findOrFail($page->brand_id);

        // Validation runs with the page's brand in context so the registry's
        // gate answers for that brand, not for the admin host (none).
        $blocks = $this->withinBrand($brand, fn (): array => $this->validateBlocks(
            $this->registry,
            array_values(array_map(fn (BlockData $block): array => $block->toArray(), $data->blocks)),
        ));

        return DB::transaction(function () use ($page, $brand, $data, $blocks): StaticPage {
            $this->guardSlug($brand, $data->slug, $page->getKey());
            $this->guardOwnedMedia($brand, $this->mediaIdsIn($blocks));

            $before = $this->mediaIdsIn(array_values($page->blocks?->toArray() ?? []));

            $page->fill([
                'title' => $data->title,
                'slug' => $data->slug,
                'blocks' => $blocks,
                'published_at' => $data->published ? ($page->published_at ?? now()) : null,
            ])->save();

            $this->syncUsages($page, $before, $this->mediaIdsIn($blocks));

            return $page->refresh();
        });
    }

    private function guardSlug(Brand $brand, string $slug, ?string $ignorePageId): void
    {
        $taken = StaticPage::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('slug', $slug)
            ->when($ignorePageId !== null, fn ($query) => $query->whereKeyNot($ignorePageId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slug' => __('This brand already has a page with that slug.')]);
        }
    }

    /** @param list<string> $ids */
    private function guardOwnedMedia(Brand $brand, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $owned = MediaItem::withoutBrandScope()->whereIn('id', $ids)->where('brand_id', $brand->getKey())->pluck('id')->all();

        if (array_diff($ids, $owned) !== []) {
            throw ValidationException::withMessages(['blocks' => __('A block references a file that belongs to a different brand.')]);
        }
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function syncUsages(StaticPage $page, array $before, array $after): void
    {
        foreach (array_diff($before, $after) as $released) {
            $this->media->releaseUsage($released, 'platform', 'static_page', $page->getKey());
        }

        foreach (array_diff($after, $before) as $used) {
            $this->media->recordUsage($used, 'platform', 'static_page', $page->getKey());
        }
    }
}
