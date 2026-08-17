<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Actions\Concerns\GuardsUniqueSlug;
use Konventix\Content\Data\ContentEntryData;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Models\ContentEntry;

/**
 * Edits an entry's content.
 *
 * Touches neither `state` nor `published_at`: an editor fixing a typo in a
 * live article must not accidentally republish it, and re-emitting
 * `ContentEntryPublished` on every save would notify people twice for one
 * announcement. Moving an entry through its lifecycle is `PublishEntry`,
 * `UnpublishEntry` and `ArchiveEntry`.
 *
 * No revision is written — D-CNT-09 settled that there is no revision table
 * at release; the audit trail records who changed what.
 */
final class UpdateEntry
{
    use GuardsUniqueSlug;

    public function handle(ContentEntry $entry, ContentEntryData $data): ContentEntry
    {
        return DB::transaction(function () use ($entry, $data): ContentEntry {
            $this->guardUniqueSlug($entry->brand_id, $data->slug, $entry->getKey());

            $entry->forceFill([
                'event_id' => $data->eventId,
                'type' => $data->type,
                'slug' => $data->slug,
                'title' => $data->title,
                'teaser' => $data->teaser,
                'body' => $data->body,
                'audience' => $data->audience,
                'urgency' => $data->type === ContentType::Announcement ? $data->urgency : null,
                'comments_enabled' => $data->commentsEnabled,
            ])->save();

            return $entry->refresh();
        });
    }
}
