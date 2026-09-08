<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Events\AnnouncementPublished;
use Konventix\Content\Events\ContentEntryPublished;
use Konventix\Content\Models\ContentEntry;

/**
 * Publishes an entry, now or at a future moment.
 *
 * A future date produces `Scheduled`, not `Published` with a date ahead:
 * "is this live" should be answerable without arithmetic, and an entry
 * unpublished before its date must not silently appear when the date arrives.
 * `PublishScheduledEntries` promotes them when the time comes.
 *
 * An announcement emits **both** `ContentEntryPublished` and
 * `AnnouncementPublished` (D-CNT-08). It is still an entry, and a consumer
 * listing content should not have to understand notification semantics to see
 * it.
 *
 * Republishing is idempotent — an already-live entry is left alone rather
 * than re-emitting and re-notifying everyone.
 *
 * The moment is normalised to UTC here, where the transition is owned, not
 * at the caller: `content_entries.published_at` is a zone-less column and
 * Eloquent formats a Carbon in whatever zone the instance carries, so a
 * moment parsed as `+02:00` would be written down as its own digits and read
 * back two hours wrong (#246). Doing it in the console controller alone left
 * the next caller free to reintroduce that with nothing in the way.
 */
final class PublishEntry
{
    public function handle(ContentEntry $entry, ?CarbonInterface $at = null): ContentEntry
    {
        return DB::transaction(function () use ($entry, $at): ContentEntry {
            if ($entry->state === PublicationState::Published) {
                return $entry;
            }

            $moment = ($at ?? now())->utc();
            $isFuture = $moment->greaterThan(now());

            $entry->forceFill([
                'state' => $isFuture ? PublicationState::Scheduled : PublicationState::Published,
                'published_at' => $moment,
            ])->save();

            if ($isFuture) {
                // Nothing is announced until it is actually live.
                return $entry->refresh();
            }

            $this->announce($entry);

            return $entry->refresh();
        });
    }

    public function announce(ContentEntry $entry): void
    {
        event(new ContentEntryPublished(
            entryId: $entry->id,
            brandId: $entry->brand_id,
            eventId: $entry->event_id,
            type: $entry->type->value,
            slug: $entry->slug,
            title: $entry->title,
            audience: $entry->audience->value,
            publishedAt: $entry->published_at->toIso8601String(),
        ));

        if ($entry->type !== ContentType::Announcement) {
            return;
        }

        event(new AnnouncementPublished(
            entryId: $entry->id,
            brandId: $entry->brand_id,
            eventId: $entry->event_id,
            title: $entry->title,
            // Intent only — Messaging maps it to channels and timing.
            urgency: ($entry->urgency ?? Urgency::Normal)->value,
            audience: $entry->audience->value,
            publishedAt: $entry->published_at->toIso8601String(),
        ));
    }
}
