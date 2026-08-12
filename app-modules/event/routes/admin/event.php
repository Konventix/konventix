<?php

declare(strict_types=1);

/*
 * Event's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Event\Http\Controllers\Admin\EventAdminController;
use Konventix\Event\Http\Controllers\Admin\EventLifecycleController;
use Konventix\Event\Http\Controllers\Admin\EventVenueController;
use Konventix\Event\Http\Controllers\Admin\VenueAdminController;

Route::get('/events', [EventAdminController::class, 'index'])->name('admin.events.index');
Route::post('/events', [EventAdminController::class, 'store'])->name('admin.events.store');
Route::get('/events/{event}', [EventAdminController::class, 'show'])->name('admin.events.show');
Route::get('/events/{event}/edit', [EventAdminController::class, 'edit'])->name('admin.events.edit');
Route::put('/events/{event}', [EventAdminController::class, 'update'])->name('admin.events.update');

// One endpoint per lifecycle Action — the Actions own every guard.
Route::post('/events/{event}/announce', [EventLifecycleController::class, 'announce'])->name('admin.events.announce');
Route::post('/events/{event}/publish', [EventLifecycleController::class, 'publish'])->name('admin.events.publish');
Route::post('/events/{event}/unpublish', [EventLifecycleController::class, 'unpublish'])->name('admin.events.unpublish');
Route::post('/events/{event}/start', [EventLifecycleController::class, 'start'])->name('admin.events.start');
Route::post('/events/{event}/conclude', [EventLifecycleController::class, 'conclude'])->name('admin.events.conclude');
Route::post('/events/{event}/cancel', [EventLifecycleController::class, 'cancel'])->name('admin.events.cancel');
Route::post('/events/{event}/archive', [EventLifecycleController::class, 'archive'])->name('admin.events.archive');

// Venue assignment on an event (D-EVT-15).
Route::post('/events/{event}/venues', [EventVenueController::class, 'store'])->name('admin.events.venues.store');
Route::delete('/events/{event}/venues/{venue}', [EventVenueController::class, 'destroy'])->name('admin.events.venues.destroy');

// Brand-owned reusable venues.
Route::get('/venues', [VenueAdminController::class, 'index'])->name('admin.venues.index');
Route::post('/venues', [VenueAdminController::class, 'store'])->name('admin.venues.store');
Route::put('/venues/{venue}', [VenueAdminController::class, 'update'])->name('admin.venues.update');
