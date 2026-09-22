<?php

declare(strict_types=1);

/*
 * Routes owned by the Community module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:community` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Community\Http\Controllers\ProfileCommentController;
use Konventix\Community\Http\Controllers\ProfileController;
use Konventix\Community\Http\Controllers\RoomController;

// The account's public page (ADR-0051 §7, ADR-0052). Deliberately NOT in an
// auth group: a guest may view a `public` profile, and every other denial
// must read as 404 rather than a login redirect — the gate itself lives in
// ProfileController::show() via ResolvesProfiles.
Route::get('/u/{username}', [ProfileController::class, 'show'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.show');

// The wall's comments and reactions (Task 9). Also deliberately NOT in an
// auth group, for the same reason: comments stay internal-scope under every
// visibility (ADR-0052 §2), and the resolver's own 404 wall — extended to
// require the viewer's own membership — is the only gate these routes get.
Route::post('/u/{username}/comments', [ProfileCommentController::class, 'store'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.comments.store');
Route::delete('/u/{username}/comments/{comment}', [ProfileCommentController::class, 'destroy'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.comments.destroy');
Route::post('/u/{username}/comments/{comment}/react', [ProfileCommentController::class, 'react'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.comments.react');
Route::delete('/u/{username}/comments/{comment}/react', [ProfileCommentController::class, 'unreact'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.comments.unreact');

// The event shoutbox (design §8). `auth` only: whether this member may read
// the wall is `RoomController`'s question — the room is derived from their
// own running event and `RoomChannel::join()` decides, so a member nobody
// admitted gets a 404 rather than a redirect. `/live/shoutbox` sits beside
// Platform's `/live` hub and deliberately not behind its
// `ensure-active-event`, whose home-with-a-toast is the hub's answer, not
// this page's. Posting also passes the coarse half of D-COM-09's budget
// (`throttle:shoutbox-post`, `CommunityServiceProvider`).
Route::middleware('auth')->prefix('live/shoutbox')->name('community.shoutbox.')->group(function (): void {
    Route::get('/', [RoomController::class, 'show'])->name('show');
    Route::get('/messages', [RoomController::class, 'messages'])->name('messages');
    Route::post('/messages', [RoomController::class, 'store'])
        ->middleware('throttle:shoutbox-post')
        ->name('messages.store');
    Route::post('/messages/{message}/report', [RoomController::class, 'report'])
        ->whereUlid('message')
        ->name('messages.report');
    Route::post('/presence', [RoomController::class, 'presence'])->name('presence');
    Route::delete('/presence', [RoomController::class, 'leave'])->name('presence.leave');
});
