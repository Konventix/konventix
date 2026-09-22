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
