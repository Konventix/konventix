<?php

declare(strict_types=1);

/*
 * Competition's operator-console routes (ADR-0029, design §9). Loaded by the
 * base ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth` and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those. No `ensure-module` middleware either:
 * the admin host has no brand to gate on; each controller method opens with
 * `Gate::authorize()` instead.
 *
 * `/competitions/create` is declared before the `{competition}` routes so the
 * literal segment wins.
 *
 * The lifecycle (design §4) is one `POST .../transition` rather than seven
 * verb routes: the target state is the payload, `CompetitionStatus`'s
 * transition table is the authority, and the controller resolves the Action
 * that owns the arrow. Seven routes would be seven places to forget the
 * table.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Competition\Http\Controllers\Admin\CheckInDeskController;
use Konventix\Competition\Http\Controllers\Admin\CompetitionAdminController;
use Konventix\Competition\Http\Controllers\Admin\DeskResultController;
use Konventix\Competition\Http\Controllers\Admin\DisciplineAdminController;
use Konventix\Competition\Http\Controllers\Admin\EntrantAdminController;
use Konventix\Competition\Http\Controllers\Admin\TournamentDeskController;

// Disciplines (design §9): what is being contested, and its defaults.
Route::get('/disciplines', [DisciplineAdminController::class, 'index'])->name('admin.disciplines.index');
Route::post('/disciplines', [DisciplineAdminController::class, 'store'])->name('admin.disciplines.store');
Route::get('/disciplines/{discipline}/edit', [DisciplineAdminController::class, 'edit'])->name('admin.disciplines.edit');
Route::put('/disciplines/{discipline}', [DisciplineAdminController::class, 'update'])->name('admin.disciplines.update');

// Competitions (design §9) and their lifecycle (§4).
Route::get('/competitions', [CompetitionAdminController::class, 'index'])->name('admin.competitions.index');
Route::get('/competitions/create', [CompetitionAdminController::class, 'create'])->name('admin.competitions.create');
Route::post('/competitions', [CompetitionAdminController::class, 'store'])->name('admin.competitions.store');
Route::get('/competitions/{competition}/edit', [CompetitionAdminController::class, 'edit'])->name('admin.competitions.edit');
Route::put('/competitions/{competition}', [CompetitionAdminController::class, 'update'])->name('admin.competitions.update');
Route::post('/competitions/{competition}/transition', [CompetitionAdminController::class, 'transition'])->name('admin.competitions.transition');

/*
 * The field of one competition (design §5.2, §5.4, §5.5): §5.4's prompt for
 * every incomplete roster, and the organizer powers behind `ManageEntrants`.
 * Each method opens with its own `Gate::authorize()` — the admin host has no
 * brand to gate on, so the capability is the only filter.
 */
Route::get('/competitions/{competition}/entrants', [EntrantAdminController::class, 'index'])
    ->name('admin.competitions.entrants');

Route::post('/entrants/{entrant}/fill', [EntrantAdminController::class, 'fill'])
    ->name('admin.entrants.fill');
Route::post('/entrants/{entrant}/decline', [EntrantAdminController::class, 'decline'])
    ->name('admin.entrants.decline');
Route::post('/entrants/{entrant}/withdraw', [EntrantAdminController::class, 'withdraw'])
    ->name('admin.entrants.withdraw');
Route::post('/entrants/{entrant}/disqualify', [EntrantAdminController::class, 'disqualify'])
    ->name('admin.entrants.disqualify');

Route::put('/competition-teams/{team}', [EntrantAdminController::class, 'renameTeam'])
    ->name('admin.competition-teams.rename');
Route::post('/competition-teams/{team}/invite-code', [EntrantAdminController::class, 'regenerateCode'])
    ->name('admin.competition-teams.invite-code');
Route::post('/competition-teams/{team}/members', [EntrantAdminController::class, 'addMember'])
    ->name('admin.competition-teams.members.add');
Route::delete('/competition-teams/{team}/members', [EntrantAdminController::class, 'removeMember'])
    ->name('admin.competition-teams.members.remove');
Route::post('/competition-teams/{team}/members/move', [EntrantAdminController::class, 'moveMember'])
    ->name('admin.competition-teams.members.move');

/*
 * The desk's check-in list (design §6, §9). Its own route rather than a panel
 * inside `…/{competition}/desk`, which §9's own slice builds: the two would
 * otherwise race for the same controller, and the check-in list is usable on
 * its own at a table by the door.
 *
 * Everything here is `CheckInEntrants` — the desk may check a team in or take
 * it back whatever the competition's mode says (§6), which is why neither
 * write asks the mode.
 */
Route::get('/competitions/{competition}/check-in', [CheckInDeskController::class, 'index'])
    ->name('admin.competitions.check-in');

Route::post('/entrants/{entrant}/check-in', [CheckInDeskController::class, 'checkIn'])
    ->name('admin.entrants.check-in');
Route::delete('/entrants/{entrant}/check-in', [CheckInDeskController::class, 'undo'])
    ->name('admin.entrants.check-in.undo');

/*
 * The tournament desk (design §9, D-CMP-20, D-CMP-21). The page opens for any
 * of §9's four desk capabilities; each write asks for its own —
 * `ManageSchedule` to seed, draw and time the matches, `RecordResults` to
 * record, resolve and work the verification queue, `CorrectResults` to
 * correct and to resolve a dispute raised against a standing result (§8.4).
 * The check-in list stays on its own route above and the desk links to it.
 */
Route::get('/competitions/{competition}/desk', [TournamentDeskController::class, 'show'])
    ->name('admin.competitions.desk');

Route::post('/competition-stages/{stage}/seed', [TournamentDeskController::class, 'seed'])
    ->name('admin.competition-stages.seed');
Route::post('/competition-stages/{stage}/shuffle', [TournamentDeskController::class, 'shuffle'])
    ->name('admin.competition-stages.shuffle');
Route::post('/competition-stages/{stage}/generate', [TournamentDeskController::class, 'generate'])
    ->name('admin.competition-stages.generate');
Route::post('/competition-stages/{stage}/regenerate', [TournamentDeskController::class, 'regenerate'])
    ->name('admin.competition-stages.regenerate');
Route::post('/competition-stages/{stage}/reset', [TournamentDeskController::class, 'reset'])
    ->name('admin.competition-stages.reset');
Route::post('/competition-stages/{stage}/schedule-round', [TournamentDeskController::class, 'scheduleRound'])
    ->name('admin.competition-stages.schedule-round');
Route::put('/competition-matches/{match}/schedule', [TournamentDeskController::class, 'scheduleMatch'])
    ->name('admin.competition-matches.schedule');

Route::post('/competition-matches/{match}/record', [DeskResultController::class, 'record'])
    ->name('admin.competition-matches.record');
Route::post('/competition-matches/{match}/correct', [DeskResultController::class, 'correct'])
    ->name('admin.competition-matches.correct');
Route::post('/competition-matches/{match}/resolve', [DeskResultController::class, 'resolve'])
    ->name('admin.competition-matches.resolve');
Route::post('/competition-stages/{stage}/score-entries', [DeskResultController::class, 'enterScore'])
    ->name('admin.competition-stages.score-entries');
Route::post('/competition-score-entries/{entry}/verify', [DeskResultController::class, 'verify'])
    ->name('admin.competition-score-entries.verify');
Route::post('/competition-score-entries/{entry}/reject', [DeskResultController::class, 'reject'])
    ->name('admin.competition-score-entries.reject');
