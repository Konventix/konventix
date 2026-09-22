<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;

/**
 * Takes somebody back off the public interested list (design §5.3).
 *
 * A hard delete, not a flag: the row exists to say "ask me", and a person who
 * has changed their mind should stop appearing on a page their friends can
 * read. Nothing downstream keeps a reference to it.
 *
 * Silent when there is nothing to remove — a double-clicked "remove me" is
 * not an error worth showing anybody.
 */
final class WithdrawInterest
{
    public function handle(Competition $competition, string $userId): void
    {
        CompetitionInterest::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->where('user_id', $userId)
            ->delete();
    }
}
