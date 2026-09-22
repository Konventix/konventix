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
use Konventix\Competition\Http\Controllers\Admin\CompetitionAdminController;
use Konventix\Competition\Http\Controllers\Admin\DisciplineAdminController;
use Konventix\Competition\Http\Controllers\Admin\EntrantAdminController;

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
