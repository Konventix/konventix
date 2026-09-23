<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionUnpublished;
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
 * Emits `CompetitionUnpublished` (0.9.1, #331; design §14 "Unpublishing a
 * competition tells nobody"), so a consumer that projected the competition on
 * `CompetitionPublished` can forget it. The dispatch is registered only after
 * `transition()` has succeeded: a refused unpublish throws before it and
 * announces nothing.
 */
final class UnpublishCompetition
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $competition = $this->transition(
                $competition,
                CompetitionStatus::Draft,
                // `signup_opened_at` too: a competition published again is
                // announced again when its window is open (`AnnounceSignupOpened`).
                ['published_at' => null, 'signup_opened_at' => null],
            );

            DB::afterCommit(static function () use ($competition): void {
                event(new CompetitionUnpublished(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    brandId: $competition->brand_id,
                    unpublishedAt: now()->toIso8601String(),
                ));
            });

            return $competition;
        });
    }
}
