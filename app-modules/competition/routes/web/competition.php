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
use Konventix\Competition\Http\Controllers\CompetitionPageController;
use Konventix\Competition\Http\Controllers\EventTournamentsController;
use Konventix\Competition\Http\Controllers\InterestController;
use Konventix\Competition\Http\Controllers\MatchPageController;
use Konventix\Competition\Http\Controllers\MyTournamentsController;
use Konventix\Competition\Http\Controllers\NextGamesController;
use Konventix\Competition\Http\Controllers\ProofUploadController;
use Konventix\Competition\Http\Controllers\TeamJoinController;
use Konventix\Competition\Http\Controllers\TeamPageController;

/*
 * The public tournament pages (design §10, D-CMP-22). Genuinely public: no
 * auth and no capability — a bracket is an announcement. The only gate is
 * `ensure-module:competition`, which the provider wraps this file in.
 *
 * `{slug}` and not `{event:slug}`: Event's model is out of reach (ADR-0004),
 * so the slug resolves against Competition's own `EventSummary`. The pattern
 * is the one `Str::slug()` produces, like Agenda's programme route. The
 * competition is addressed by its slug too (unique per brand); a match and a
 * team by their ULIDs.
 */
Route::prefix('/events/{slug}/tournaments')
    ->where(['slug' => '[a-z0-9-]+'])
    ->group(function (): void {
        Route::get('/', EventTournamentsController::class)
            ->name('competition.event.tournaments');

        Route::get('/{competition}', [CompetitionPageController::class, 'show'])
            ->where('competition', '[a-z0-9-]+')
            ->name('competition.page');

        Route::get('/{competition}/matches/{match}', [MatchPageController::class, 'show'])
            ->where('competition', '[a-z0-9-]+')
            ->whereUlid('match')
            ->name('competition.match.page');

        Route::get('/{competition}/teams/{team}', TeamPageController::class)
            ->where('competition', '[a-z0-9-]+')
            ->whereUlid('team')
            ->name('competition.team.page');
    });

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

    /*
     * The hub's *Nächste Spiele* card refreshing itself (design §10a
     * "Liveness", hub design §7.1): the card's own payload as JSON, fetched
     * on its interval, so the rest of `/live` is never rebuilt for it and
     * `platform_hub_views_total` counts people, not polls. Beside `/live`
     * like Community's `/live/shoutbox/messages`, and like it not behind
     * Platform's `ensure-active-event`, whose redirect home is the hub
     * page's answer: the controller asks the same `ActiveEventContext` and
     * answers a member nobody admitted with a 404. Its own named budget,
     * `competition-hub`, well above one poll a minute per open tab.
     */
    Route::get('/live/next-games', NextGamesController::class)
        ->middleware('throttle:competition-hub')
        ->name('competition.hub.next-games');

    /*
     * Every participant write, on one named budget: `competition-participant`
     * (CompetitionServiceProvider::configureRateLimiting()), ten a minute per
     * account. Named rather than `throttle:6,1`, whose unprefixed key is the
     * account alone and is therefore one counter shared with every other
     * unnamed throttle in the application. Ten leaves room for a whole
     * match — enter, report, confirm — and for a leaderboard player filing
     * several heats with a typo or two, and still bounds code guessing on
     * join-by-code and the 8 MB proof upload.
     */
    Route::middleware('throttle:competition-participant')->group(function (): void {
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
         * Self-service check-in (design §6): the captain, inside the window
         * of `check_in_opens_minutes_before` minutes before `starts_at`.
         * There is no undo here on purpose — §6 gives the captain one
         * direction and the desk both.
         */
        Route::post('/my/tournaments/entries/{entrant}/check-in', [MyTournamentsController::class, 'checkIn'])
            ->whereUlid('entrant')
            ->name('competition.my.entries.check-in');

        /*
         * The participant writes of §10's pages: entering a team (§5.2), a
         * match report, its confirmation and a dispute (§8.2, §8.4), and a
         * leaderboard score (§8.3). Who may speak for a side is membership,
         * which the controllers derive from the roster rather than from the
         * request (§11).
         */
        Route::post('/tournaments/{competition}/entries', [CompetitionPageController::class, 'enter'])
            ->whereUlid('competition')
            ->name('competition.entries.store');

        Route::post('/tournaments/{competition}/score-entries', [CompetitionPageController::class, 'submitScore'])
            ->whereUlid('competition')
            ->name('competition.score-entries.store');

        Route::post('/tournaments/{competition}/matches/{match}/report', [MatchPageController::class, 'report'])
            ->whereUlid(['competition', 'match'])
            ->name('competition.matches.report');

        Route::post('/tournaments/{competition}/matches/{match}/confirm', [MatchPageController::class, 'confirm'])
            ->whereUlid(['competition', 'match'])
            ->name('competition.matches.confirm');

        Route::post('/tournaments/{competition}/matches/{match}/dispute', [MatchPageController::class, 'dispute'])
            ->whereUlid(['competition', 'match'])
            ->name('competition.matches.dispute');

        Route::post('/tournaments/{competition}/interest', [InterestController::class, 'store'])
            ->whereUlid('competition')
            ->name('competition.interest.store');
        Route::delete('/tournaments/{competition}/interest', [InterestController::class, 'destroy'])
            ->whereUlid('competition')
            ->name('competition.interest.destroy');

        /*
         * A proof image for a reported result or a score entry (design §8.7).
         *
         * Its own endpoint rather than a file field on the report, because
         * the competition's `proof_policy` has to be able to refuse
         * **before** the file is stored: `proof_media_id` is a ULID, so a
         * policy of `none` checked only at submission time would accept the
         * upload, reject the whole result over the attachment, and leave the
         * object behind. Who may attach a proof is membership in the side,
         * which the Action checks (§11).
         *
         * Live ahead of #322: a proof stored here is readable by anyone
         * holding its ULID until Platform's per-object read authorization
         * lands (§8.7). No screen offers an upload until then.
         */
        Route::post('/tournaments/{competition}/proofs', [ProofUploadController::class, 'store'])
            ->whereUlid('competition')
            ->name('competition.proofs.store');
    });
});
