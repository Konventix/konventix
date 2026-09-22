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

    Route::post('/tournaments/{competition}/interest', [InterestController::class, 'store'])
        ->whereUlid('competition')
        ->name('competition.interest.store');
    Route::delete('/tournaments/{competition}/interest', [InterestController::class, 'destroy'])
        ->whereUlid('competition')
        ->name('competition.interest.destroy');
});
