<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Models\Competition;

/**
 * Takes an announced competition back off the public pages:
 * `Published → Draft`, the unpublish arrow of design §4.
 *
 * `published_at` is cleared with it — it is the timestamp of *being*
 * published, and a draft carrying one would make every "is this public?"
 * read two fields and disagree with itself. A later publish stamps a fresh
 * one.
 *
 * No event: §12's contract names none for this direction, and inventing one
 * would put a name in other modules' code that the design never settled.
 */
final class UnpublishCompetition
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(fn (): Competition => $this->transition(
            $competition,
            CompetitionStatus::Draft,
            // `signup_opened_at` too: a competition published again is
            // announced again when its window is open (`AnnounceSignupOpened`).
            ['published_at' => null, 'signup_opened_at' => null],
        ));
    }
}
