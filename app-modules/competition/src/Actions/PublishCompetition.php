<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionPublished;
use Konventix\Competition\Events\SignupOpened;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * Announces a competition: `Draft → Published` (D-CMP-15, design §4).
 *
 * Signup is *derived* from here on — the window decides, not the state — so
 * publishing into a window that already contains now is the moment signup
 * opens, and `SignupOpened` says so. Publishing ahead of the window emits
 * nothing further; the scheduler tick that reaches the window does.
 *
 * `published_at` is set here rather than being the state: it is the
 * timestamp of this transition, which is all it ever meant.
 */
final class PublishCompetition
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $competition = $this->transition($competition, CompetitionStatus::Published, [
                'published_at' => $competition->published_at ?? now(),
            ]);

            $signupIsOpen = $competition->signupIsOpen();
            $entrantCount = Entrant::countSlotHolders($competition->brand_id, $competition->id);

            DB::afterCommit(static function () use ($competition, $signupIsOpen, $entrantCount): void {
                event(new CompetitionPublished(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    disciplineId: $competition->discipline_id,
                    brandId: $competition->brand_id,
                    name: $competition->name,
                    status: $competition->status->value,
                    startsAt: $competition->starts_at?->toIso8601String(),
                    at: now()->toIso8601String(),
                ));

                if ($signupIsOpen) {
                    event(new SignupOpened(
                        competitionId: $competition->id,
                        eventId: $competition->event_id,
                        brandId: $competition->brand_id,
                        entrantCount: $entrantCount,
                        at: now()->toIso8601String(),
                    ));
                }
            });

            return $competition;
        });
    }
}
