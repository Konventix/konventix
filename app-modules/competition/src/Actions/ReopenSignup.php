<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\SignupOpened;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;

/**
 * Puts a competition back on signup: `SignupClosed → Published`, the reopen
 * arrow of design §4.
 *
 * `SignupOpened` is emitted because §4 says the organizer reopening is one
 * of its two causes. Whether anybody can actually enter still depends on the
 * window — a reopen over a window that has already passed announces the
 * intent and leaves `signupIsOpen()` false, which is why the console offers
 * the signup dates on the same screen.
 *
 * **Not over a drawn bracket.** A reopen takes the competition back to
 * `Published`, where signup can add entries the bracket would never draw, and
 * where "play" is still allowed (design §14, "where in the lifecycle the desk
 * may draw, empty and play": a draw is never made in `Published` for exactly
 * that reason). So it is refused while any stage is drawn, and the desk resets
 * the draw first (final review, part 1, I4).
 *
 * **Over a passed `signup_closes_at` the reopen does not last.** The
 * scheduler tick (`competition:signup-tick`, design §4) closes every
 * `Published` competition whose close date has passed, so it closes this one
 * again within a minute. The organizer moves the close date on the same
 * screen; that is what reopening over a passed window means.
 */
final class ReopenSignup
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            // Under the row lock the draw Actions take too, so a draw cannot
            // land between this check and the transition.
            Competition::withoutBrandScope()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();
            $competition->refresh();

            // Only a reopen the transition table allows is asked about the
            // draw; any other is refused below as the illegal move it is.
            $drawn = $competition->status->canTransitionTo(CompetitionStatus::Published) && Stage::withoutBrandScope()
                ->where('brand_id', $competition->brand_id)
                ->where('competition_id', $competition->getKey())
                ->whereNotNull('generated_at')
                ->exists();

            if ($drawn) {
                throw IllegalCompetitionStateTransitionException::drawnBracketBlocksReopen((string) $competition->getKey());
            }

            // Stamped like the tick's announcement (`AnnounceSignupOpened`),
            // because this emits the same event: the tick must not say it a
            // second time a minute later.
            $competition = $this->transition($competition, CompetitionStatus::Published, [
                'signup_opened_at' => now(),
            ]);

            $entrantCount = Entrant::countSlotHolders($competition->brand_id, $competition->id);

            DB::afterCommit(static function () use ($competition, $entrantCount): void {
                event(new SignupOpened(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    brandId: $competition->brand_id,
                    entrantCount: $entrantCount,
                    at: now()->toIso8601String(),
                ));
            });

            return $competition;
        });
    }
}
