<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Models\ContentEntry;

/**
 * Promotes scheduled entries whose moment has arrived.
 *
 * Only `Scheduled` ones: an entry unpublished before its date stays
 * unpublished, which is the whole reason `Scheduled` is its own state rather
 * than a published row with a future timestamp.
 *
 * Announcing happens here rather than at scheduling time, so a scheduled
 * announcement notifies people when it goes live and not a week early.
 */
final class PublishScheduledEntries
{
    public function __construct(
        private readonly PublishEntry $publishEntry,
    ) {}

    /**
     * @return int number of entries promoted
     */
    public function handle(): int
    {
        $promoted = 0;

        $due = ContentEntry::withoutBrandScope()
            ->where('state', PublicationState::Scheduled)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->cursor();

        foreach ($due as $entry) {
            $entry->forceFill(['state' => PublicationState::Published])->save();
            $this->publishEntry->announce($entry->refresh());
            $promoted++;
        }

        return $promoted;
    }
}
