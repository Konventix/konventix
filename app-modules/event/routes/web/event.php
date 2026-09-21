<?php

declare(strict_types=1);

/*
 * Routes owned by the Event module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:event` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Event\Http\Controllers\EventLandingController;
use Konventix\Event\Http\Controllers\VenueLandingController;
use Konventix\Event\Http\Middleware\RecordCalendarDownloadMetrics;

// The brand's public event list — the topbar's "Events" entry (registered in
// EventServiceProvider) points here. It enumerates only what
// `Event::scopeListedPublicly()` allows: unlisted events keep their landing
// page and stay out of this list.
Route::get('/events', [EventLandingController::class, 'index'])
    ->name('event.index');

// The public event landing page, resolved on the brand's own domain
// (SPEC.md "Event visibility"). `{event:slug}` binds by slug for a readable
// public URL; the model's primary key stays the ULID (ADR-0014) — this is a
// display convenience for the one public route, not a second key. Brand
// scoping comes from `BelongsToBrand`'s global scope, already in effect by
// the time implicit binding runs.
Route::get('/events/{event:slug}', [EventLandingController::class, 'show'])
    ->name('event.show');

// The event's calendar file (spec §6.2): public, no authentication, the
// same visibility rule `show()` enforces. `.ics` sits in the URI itself,
// not a query string, so both a calendar app's "subscribe by URL" and a
// plain browser download just work.
Route::get('/events/{event:slug}/calendar.ics', [EventLandingController::class, 'calendar'])
    ->middleware(RecordCalendarDownloadMetrics::class)
    ->name('event.calendar');

// The public venue page (Task 18). `{venue:slug}` binds by slug, per brand,
// for the same readable-URL reason `event.show` does — the primary key
// stays the ULID (ADR-0014). The page resolves only while at least one
// publicly visible event happens here (`Venue::isPubliclyReachable()`): a
// venue is public *through* an event, never on its own.
Route::get('/venues/{venue:slug}', [VenueLandingController::class, 'show'])
    ->name('venue.show');
