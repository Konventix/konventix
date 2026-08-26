<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\CreateStaticPageData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;

/**
 * Creates an empty static page on the chosen brand (ROADMAP slice 21a). The
 * page starts unpublished and blockless — the composer is where an operator
 * builds it out, immediately after creation.
 */
final class CreateStaticPage
{
    public function handle(Brand $brand, CreateStaticPageData $data): StaticPage
    {
        return DB::transaction(function () use ($brand, $data): StaticPage {
            $this->guardSlug($brand, $data->slug, null);

            $page = new StaticPage(['title' => $data->title, 'slug' => $data->slug, 'blocks' => [], 'published_at' => null]);
            // The admin host resolves no brand, so BelongsToBrand has nothing
            // to stamp: the page's brand is the operator's explicit choice.
            $page->brand_id = $brand->getKey();
            $page->save();

            return $page;
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
}
