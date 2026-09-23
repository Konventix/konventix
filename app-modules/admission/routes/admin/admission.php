<?php

declare(strict_types=1);

/*
 * Admission's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Admission\Http\Controllers\Admin\AdmissionStatsController;
use Konventix\Admission\Http\Controllers\Admin\AttendeeListController;
use Konventix\Admission\Http\Controllers\Admin\ConsoleCheckInController;
use Konventix\Admission\Http\Controllers\Admin\TicketDetailController;
use Konventix\Admission\Http\Controllers\BadgeController;

Route::get('/events/{event}/admission-stats', AdmissionStatsController::class)
    ->name('admin.events.admission-stats');

Route::get('/admissions/{admission}/badge', [BadgeController::class, 'show'])
    ->name('admin.admissions.badge');

// The attendee list (#329, attendee-list design §4.1): gated by
// `admission.check-in-attendees`, then scoped to the host's operator.
Route::get('/events/{event}/attendees', [AttendeeListController::class, 'index'])
    ->name('admin.events.attendees.index');

// The ticket detail page and the console check-in / check-out (#329,
// attendee-list design §4.3, §4.4, §5): the same capability and operator
// scope, and both writes spend the named `admission-console-check-in`
// budget (AdmissionServiceProvider::configureRateLimiting()).
Route::get('/tickets/{admission}', [TicketDetailController::class, 'show'])
    ->name('admin.tickets.show');

Route::post('/tickets/{admission}/check-in', [ConsoleCheckInController::class, 'checkIn'])
    ->middleware('throttle:admission-console-check-in')
    ->name('admin.tickets.check-in');

Route::post('/tickets/{admission}/check-out', [ConsoleCheckInController::class, 'checkOut'])
    ->middleware('throttle:admission-console-check-in')
    ->name('admin.tickets.check-out');
