<?php

declare(strict_types=1);

namespace Konventix\Platform\Navigation;

use Illuminate\Support\Collection;
use Konventix\Platform\Data\BrandMenuEntryData;
use Konventix\Platform\Enums\BrandMenuItemKind;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandMenuItem;
use Konventix\Platform\Pages\PageAccess;

/**
 * The brand's own topbar entries, resolved for one viewer: what the shared
 * `brandMenu` prop carries, rendered after the module entries `nav` holds.
 *
 * One eager query for the rows and their pages; then a page entry whose
 * page is unpublished or not readable by this viewer is dropped, and a
 * parent left with no children is dropped with it — so the shell never
 * renders a heading over nothing, and never learns the visibility rule.
 *
 * Stateless — a plain container resolution; the per-request memo lives in
 * `PageAccess`.
 */
final class BrandMenu
{
    public function __construct(private readonly PageAccess $access) {}

    /**
     * @return array<int, BrandMenuEntryData>
     */
    public function forViewer(Brand $brand, ?string $viewerId): array
    {
        $items = BrandMenuItem::query()
            ->with('page')
            ->inDisplayOrder()
            ->get();

        $children = $items->whereNotNull('parent_id')->groupBy('parent_id');

        return $items
            ->whereNull('parent_id')
            ->map(fn (BrandMenuItem $item): ?BrandMenuEntryData => $this->entry($item, $children->get($item->getKey(), collect()), $brand, $viewerId))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BrandMenuItem>  $children
     */
    private function entry(BrandMenuItem $item, Collection $children, Brand $brand, ?string $viewerId): ?BrandMenuEntryData
    {
        return match ($item->kind) {
            BrandMenuItemKind::Page => $this->pageEntry($item, $viewerId),
            BrandMenuItemKind::Url => $this->urlEntry($item),
            BrandMenuItemKind::Parent => $this->parentEntry($item, $children, $brand, $viewerId),
        };
    }

    private function pageEntry(BrandMenuItem $item, ?string $viewerId): ?BrandMenuEntryData
    {
        $page = $item->page;

        if ($page === null || $page->published_at === null || ! $this->access->allows($page, $viewerId)) {
            return null;
        }

        return new BrandMenuEntryData(
            label: $item->label ?? $page->title,
            url: $page->slug === 'home' ? '/' : '/pages/'.$page->slug,
            external: false,
            newTab: false,
            children: [],
        );
    }

    private function urlEntry(BrandMenuItem $item): ?BrandMenuEntryData
    {
        if ($item->url === null || $item->label === null) {
            return null;
        }

        return new BrandMenuEntryData(
            label: $item->label,
            url: $item->url,
            // A root-relative path is one of this site's own pages and an
            // Inertia visit; anything else leaves the site and gets a plain
            // anchor, the same distinction `NavigationItem::$external` draws.
            external: ! str_starts_with($item->url, '/'),
            newTab: $item->opens_in_new_tab,
            children: [],
        );
    }

    /**
     * @param  Collection<int, BrandMenuItem>  $children
     */
    private function parentEntry(BrandMenuItem $item, Collection $children, Brand $brand, ?string $viewerId): ?BrandMenuEntryData
    {
        $resolved = $children
            ->map(fn (BrandMenuItem $child): ?BrandMenuEntryData => $child->kind === BrandMenuItemKind::Parent
                ? null
                : $this->entry($child, collect(), $brand, $viewerId))
            ->filter()
            ->values()
            ->all();

        if ($resolved === [] || $item->label === null) {
            return null;
        }

        return new BrandMenuEntryData(
            label: $item->label,
            url: null,
            external: false,
            newTab: false,
            children: $resolved,
        );
    }
}
