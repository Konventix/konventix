<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Models\ContentEntry;

/**
 * Takes an entry back out of public view.
 *
 * Works on a `Scheduled` entry too, and that is the point of keeping
 * `Scheduled` a distinct state: unpublishing something before its date has to
 * actually stop it appearing, which a "published with a future date" model
 * could not guarantee without also clearing the date.
 *
 * `published_at` is deliberately kept: it is the record of when this was last
 * live, and republishing should not pretend it never was.
 */
final class UnpublishEntry
{
    public function handle(ContentEntry $entry): ContentEntry
    {
        return DB::transaction(function () use ($entry): ContentEntry {
            if (! in_array($entry->state, [PublicationState::Published, PublicationState::Scheduled], true)) {
                return $entry;
            }

            $entry->forceFill(['state' => PublicationState::Unpublished])->save();

            return $entry->refresh();
        });
    }
}
