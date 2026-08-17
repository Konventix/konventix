<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Models\ContentEntry;

/**
 * Retires an entry for good.
 *
 * Archived is where entries go to stop cluttering the editor's list, so it is
 * reachable from any state — including a live one, which it takes off the
 * site on the way, since `Live` asks for `Published`.
 *
 * Deliberately not a delete: an archived entry keeps its slug reserved and
 * its comments attached, and a deletion is a Compliance concern with its own
 * machinery (retention, tombstones), not an editor button.
 */
final class ArchiveEntry
{
    public function handle(ContentEntry $entry): ContentEntry
    {
        return DB::transaction(function () use ($entry): ContentEntry {
            if ($entry->state === PublicationState::Archived) {
                return $entry;
            }

            $entry->forceFill(['state' => PublicationState::Archived])->save();

            return $entry->refresh();
        });
    }
}
