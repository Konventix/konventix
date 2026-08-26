<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Blocks\ValidatesBlockComposition;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\StaticPage;

/**
 * Deletes a page and releases every media usage it held (ADR-0035) — nothing
 * else references a static page's blocks, so nothing else needs telling.
 */
final class DeleteStaticPage
{
    use ValidatesBlockComposition;

    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(StaticPage $page): void
    {
        DB::transaction(function () use ($page): void {
            $ids = $this->mediaIdsIn(array_values($page->blocks?->toArray() ?? []));

            foreach ($ids as $id) {
                $this->media->releaseUsage($id, 'platform', 'static_page', $page->getKey());
            }

            $page->delete();
        });
    }
}
