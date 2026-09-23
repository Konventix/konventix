<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Events\SignupOpened;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * Says that a `Published` competition's signup window has opened (design §4:
 * "`SignupOpened` is emitted when a `Published` competition's window opens
 * (scheduler tick) or when the organizer reopens").
 *
 * No state moves here. In `Published` signup is derived from the window
 * (`Competition::signupIsOpen()`), so the one thing this writes is
 * `signup_opened_at`, the record that the announcement went out. The
 * scheduler tick (`competition:signup-tick`) is the caller; `ReopenSignup`
 * emits the same event itself and stamps the same column.
 *
 * **Once per window.** A competition is due when signup is open now and
 * either nothing was announced yet, or the last announcement predates the
 * window's current opening — the organizer moved `signup_opens_at` past it,
 * so the window that is open now was never announced.
 *
 * Under the competition's row lock, the one every lifecycle Action takes, so
 * two ticks, or a tick and a console click, cannot both announce.
 */
final class AnnounceSignupOpened
{
    /**
     * @return bool whether `SignupOpened` was announced
     */
    public function handle(Competition $competition, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return DB::transaction(function () use ($competition, $now): bool {
            Competition::withoutBrandScope()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();
            $competition->refresh();

            if (! self::isDue($competition, $now)) {
                return false;
            }

            $competition->forceFill(['signup_opened_at' => $now])->save();

            $entrantCount = Entrant::countSlotHolders($competition->brand_id, $competition->id);

            DB::afterCommit(static function () use ($competition, $entrantCount, $now): void {
                event(new SignupOpened(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    brandId: $competition->brand_id,
                    entrantCount: $entrantCount,
                    at: $now->toIso8601String(),
                ));
            });

            return true;
        });
    }

    public static function isDue(Competition $competition, CarbonInterface $now): bool
    {
        if (! $competition->signupIsOpen($now)) {
            return false;
        }

        if ($competition->signup_opened_at === null) {
            return true;
        }

        return $competition->signup_opens_at !== null
            && $competition->signup_opened_at->lessThan($competition->signup_opens_at);
    }
}
