<?php

declare(strict_types=1);

namespace Konventix\Content\Blocks\Types;

use Konventix\Content\Data\LatestNewsBlockData;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\ResolvesBlockProps;

/**
 * The "latest news" CMS block, contributed to **Platform's** existing block
 * registry — not a parallel one. Platform owns pages and blocks; Content only
 * supplies a type, exactly as Event supplies its hero and countdown blocks.
 *
 * Implements `ResolvesBlockProps` because the entries must be resolved when
 * the page renders. Storing them in the block would freeze whichever articles
 * were newest the day an editor dropped it in, and the block would quietly
 * become a list of old news.
 */
final class LatestNewsBlock implements BlockType, ResolvesBlockProps
{
    public function key(): string
    {
        return 'latest-news';
    }

    public function module(): string
    {
        return 'content';
    }

    public function component(): string
    {
        return 'content/blocks/LatestNewsBlock';
    }

    public function dataClass(): string
    {
        return LatestNewsBlockData::class;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $config = LatestNewsBlockData::from($data);

        $entries = ContentEntry::query()
            ->where('type', ContentType::News)
            ->where('state', PublicationState::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit($config->limit)
            ->get()
            ->map(fn (ContentEntry $entry): array => [
                'id' => $entry->id,
                'slug' => $entry->slug,
                'title' => $entry->title,
                'teaser' => $entry->teaser,
                'published_at' => $entry->published_at?->toIso8601String(),
            ])
            ->all();

        return ['heading' => $config->heading, 'entries' => $entries];
    }
}
