<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Actions\Concerns\GuardsUniqueSlug;
use Konventix\Content\Data\ContentEntryData;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Models\ContentEntry;

/**
 * Creates an entry as a draft.
 *
 * Always a draft, whatever the caller wants: publishing emits events and
 * notifies people (`PublishEntry`), so it stays one deliberate act rather
 * than a side effect of pressing "create".
 *
 * `brand_id` is passed in and force-filled rather than taken from ambient
 * context, because the operator console creates entries for a brand it is not
 * currently resolved into (ADR-0029).
 */
final class CreateEntry
{
    use GuardsUniqueSlug;

    public function handle(string $brandId, ContentEntryData $data, ?string $authorUserId = null): ContentEntry
    {
        return DB::transaction(function () use ($brandId, $data, $authorUserId): ContentEntry {
            $this->guardUniqueSlug($brandId, $data->slug);

            $entry = new ContentEntry([
                'event_id' => $data->eventId,
                'author_user_id' => $authorUserId,
                'type' => $data->type,
                'slug' => $data->slug,
                'title' => $data->title,
                'teaser' => $data->teaser,
                'body' => $data->body,
                'state' => PublicationState::Draft,
                'audience' => $data->audience,
                // Announcement-only intent (D-CNT-08) — never stored on a
                // type that has no notification meaning.
                'urgency' => $data->type === ContentType::Announcement ? $data->urgency : null,
                'published_at' => null,
                'comments_enabled' => $data->commentsEnabled,
            ]);

            $entry->forceFill(['brand_id' => $brandId])->save();

            return $entry;
        });
    }
}
