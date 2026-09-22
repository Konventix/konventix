<?php

declare(strict_types=1);

/*
 * Routes owned by the Competition module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:competition` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Competition\Http\Controllers\InterestController;
use Konventix\Competition\Http\Controllers\MyTournamentsController;
use Konventix\Competition\Http\Controllers\ProofUploadController;
use Konventix\Competition\Http\Controllers\TeamJoinController;

/*
 * The participant surfaces of signup (design §5, §10).
 *
 * `auth` and nothing else. No capability: being on a team you joined is not
 * a power anybody grants, and ownership is checked in each controller — a
 * team you are not on is a 404, never a 403, because a 403 on a ULID is an
 * answer about whether it names something.
 *
 * `/my/tournaments/join` is declared before the `{team}` routes so the
 * literal segment wins, and the interest endpoints hang off the competition
 * because the list they write to renders on the competition page (§5.3),
 * not on a page of their own.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/tournaments', [MyTournamentsController::class, 'index'])
        ->name('competition.my.tournaments');

    Route::get('/my/tournaments/join', [TeamJoinController::class, 'show'])
        ->name('competition.my.join');
    Route::post('/my/tournaments/join', [TeamJoinController::class, 'store'])
        ->name('competition.my.join.store');

    Route::post('/my/tournaments/teams', [MyTournamentsController::class, 'storeTeam'])
        ->name('competition.my.teams.store');

    Route::delete('/my/tournaments/teams/{team}/members/me', [MyTournamentsController::class, 'leave'])
        ->whereUlid('team')
        ->name('competition.my.teams.leave');

    Route::post('/my/tournaments/teams/{team}/invite-code', [MyTournamentsController::class, 'regenerateCode'])
        ->whereUlid('team')
        ->name('competition.my.teams.invite-code');

    Route::delete('/my/tournaments/entries/{entrant}', [MyTournamentsController::class, 'withdraw'])
        ->whereUlid('entrant')
        ->name('competition.my.entries.withdraw');

    /*
     * Self-service check-in (design §6): the captain, inside the window of
     * `check_in_opens_minutes_before` minutes before `starts_at`. There is no
     * undo here on purpose — §6 gives the captain one direction and the desk
     * both.
     */
    Route::post('/my/tournaments/entries/{entrant}/check-in', [MyTournamentsController::class, 'checkIn'])
        ->whereUlid('entrant')
        ->name('competition.my.entries.check-in');

    Route::post('/tournaments/{competition}/interest', [InterestController::class, 'store'])
        ->whereUlid('competition')
        ->name('competition.interest.store');
    Route::delete('/tournaments/{competition}/interest', [InterestController::class, 'destroy'])
        ->whereUlid('competition')
        ->name('competition.interest.destroy');

    /*
     * A proof image for a reported result or a score entry (design §8.7).
     *
     * Its own endpoint rather than a file field on the report, because the
     * competition's `proof_policy` has to be able to refuse **before** the
     * file is stored: `proof_media_id` is a ULID, so a policy of `none`
     * checked only at submission time would accept the upload, reject the
     * whole result over the attachment, and leave the object behind.
     *
     * `auth` like the rest of this file: who may attach a proof is
     * membership in the side, which the Action checks (§11). Throttled on top,
     * because it takes up to 8 MB per request onto the brand's media disk and
     * nothing else bounds how often: `throttle:6,1`, the repo's tightest
     * per-route budget for a signed-in write (Identity's `account-email.store`
     * and `user-password.update`).
     *
     * Live ahead of #322: a proof stored here is readable by anyone holding
     * its ULID until Platform's per-object read authorization lands (§8.7;
     * recorded in this module's TODO with what the screens owe meanwhile).
     */
    Route::post('/tournaments/{competition}/proofs', [ProofUploadController::class, 'store'])
        ->whereUlid('competition')
        ->middleware('throttle:6,1')
        ->name('competition.proofs.store');
});
