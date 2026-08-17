<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rewrites stored `hero` block payloads from a resolved `imageUrl` to an
     * `imageMediaId` (ADR-0043): a URL bakes the media disk and asset host
     * into page content, so pointing the media disk at object storage would
     * silently break every hero image.
     *
     * The ULID is recovered by matching the stored URL's tail against media
     * paths — a URL built by `MediaLibrary::urlFor()` always ends in the
     * item's own path. A URL that matches nothing (hand-written, or a
     * long-deleted item) becomes a null reference: the hero then renders
     * without an image, which is what a stale reference already does.
     *
     * Query builder rather than models on purpose — migrations must not
     * depend on model shape, and `media_items` is brand-scoped at the model
     * level while this runs outside any brand context.
     */
    public function up(): void
    {
        /** @var array<string, string> $mediaPaths stored path => media ULID */
        $mediaPaths = DB::table('media_items')
            ->select('id', 'path')
            ->get()
            // Longest path first: one item's path can be another's suffix
            // only in pathological cases, but matching the longest wins
            // deterministically if it ever happens.
            ->sortByDesc(fn (object $row): int => strlen((string) $row->path))
            ->mapWithKeys(fn (object $row): array => [(string) $row->path => (string) $row->id])
            ->all();

        DB::table('static_pages')->select('id', 'blocks')->orderBy('id')->chunk(100, function ($pages) use ($mediaPaths): void {
            foreach ($pages as $page) {
                /** @var array<int, array<string, mixed>> $blocks */
                $blocks = json_decode((string) $page->blocks, true) ?: [];
                $changed = false;

                foreach ($blocks as $index => $block) {
                    if (($block['type'] ?? null) !== 'hero' || ! array_key_exists('imageUrl', $block['data'] ?? [])) {
                        continue;
                    }

                    $url = $block['data']['imageUrl'];

                    unset($blocks[$index]['data']['imageUrl']);
                    $blocks[$index]['data']['imageMediaId'] = is_string($url) && $url !== ''
                        ? $this->mediaIdForUrl($url, $mediaPaths)
                        : null;

                    $changed = true;
                }

                if ($changed) {
                    DB::table('static_pages')
                        ->where('id', $page->id)
                        ->update(['blocks' => json_encode(array_values($blocks))]);
                }
            }
        });
    }

    /**
     * No down(): the forward direction loses the URL that a re-resolved ULID
     * reproduces anyway, and rolling back would re-bake deployment state into
     * page content. Reverting this migration leaves hero payloads on the new
     * shape, which the previous code would read as "no image".
     */
    public function down(): void {}

    /**
     * @param  array<string, string>  $mediaPaths  stored path => media ULID
     */
    private function mediaIdForUrl(string $url, array $mediaPaths): ?string
    {
        foreach ($mediaPaths as $path => $id) {
            if ($url === $path || str_ends_with($url, '/'.$path)) {
                return $id;
            }
        }

        return null;
    }
};
