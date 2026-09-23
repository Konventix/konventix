<?php

declare(strict_types=1);

/*
 * Agenda's operator-console routes (ADR-0029, design §6). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 *
 * No `ensure-module` middleware either: the admin host resolves no brand to
 * gate on. `AgendaAdminController` asks `ModuleGate::isActiveForBrand()` with
 * the event's own brand instead, and every method opens with
 * `Gate::authorize(ManageAgenda)` — the same division Resources' console
 * routes describe.
 *
 * `{event}` is the event's ULID as a plain string: this module may not
 * import Event's model for implicit binding (ADR-0004).
 */

use Illuminate\Support\Facades\Route;
use Konventix\Agenda\Http\Controllers\Admin\AgendaAdminController;

Route::get('/events/{event}/agenda', [AgendaAdminController::class, 'edit'])->name('admin.events.agenda.edit');

// Tracks and places, saved as whole sets by the page's one form.
Route::put('/events/{event}/agenda', [AgendaAdminController::class, 'update'])->name('admin.events.agenda.update');

// The competition selection (design §4.5, D-AGD-02), a whole set of its own.
Route::put('/events/{event}/agenda/competitions', [AgendaAdminController::class, 'updateCompetitions'])->name('admin.events.agenda.competitions.update');

// Items, one at a time — publishing and cancelling are separate acts with
// separate announcements behind them (design §7), so they are separate
// endpoints and not a `state` field on the form.
Route::post('/events/{event}/agenda/items', [AgendaAdminController::class, 'storeItem'])->name('admin.events.agenda.items.store');
Route::put('/events/{event}/agenda/items/{item}', [AgendaAdminController::class, 'updateItem'])->name('admin.events.agenda.items.update');
Route::post('/events/{event}/agenda/items/{item}/publish', [AgendaAdminController::class, 'publishItem'])->name('admin.events.agenda.items.publish');
Route::post('/events/{event}/agenda/items/{item}/cancel', [AgendaAdminController::class, 'cancelItem'])->name('admin.events.agenda.items.cancel');
Route::delete('/events/{event}/agenda/items/{item}', [AgendaAdminController::class, 'destroyItem'])->name('admin.events.agenda.items.destroy');
