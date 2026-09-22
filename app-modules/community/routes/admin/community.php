<?php

declare(strict_types=1);

/*
 * Community's operator-console routes (ADR-0029, design §8). Loaded by the
 * base ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth` and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 *
 * No `ensure-module` middleware either: the admin host resolves no brand to
 * gate on. `ModerationController` asks `ModuleGate::isActiveForBrand()` with
 * the room's own brand instead, and every method opens with
 * `Gate::authorize(ModerateCommunity)` — capability, never admission
 * (D-COM-04).
 *
 * `{event}` is the event's ULID as a plain string: this module may not
 * import Event's model for implicit binding (ADR-0004). `{user}` is an
 * account ULID for the same reason.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Community\Http\Controllers\ModerationController;

Route::prefix('/events/{event}/shoutbox')
    ->whereUlid('event')
    ->name('admin.events.shoutbox.')
    ->group(function (): void {
        Route::get('/', [ModerationController::class, 'show'])->name('show');

        // The three verbs of D-COM-08, and the queue they are worked from.
        Route::post('/lock', [ModerationController::class, 'lock'])->name('lock');
        Route::delete('/lock', [ModerationController::class, 'unlock'])->name('unlock');
        Route::delete('/messages/{message}', [ModerationController::class, 'remove'])->whereUlid('message')->name('messages.remove');
        Route::post('/mutes/{user}', [ModerationController::class, 'mute'])->whereUlid('user')->name('mutes.store');
        Route::delete('/mutes/{user}', [ModerationController::class, 'unmute'])->whereUlid('user')->name('mutes.destroy');
        Route::post('/reports/{report}/resolve', [ModerationController::class, 'resolve'])->whereUlid('report')->name('reports.resolve');
    });
