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
use Konventix\Community\Http\Controllers\ProfileController;

// The account's public page (ADR-0051 §7, ADR-0052). Deliberately NOT in an
// auth group: a guest may view a `public` profile, and every other denial
// must read as 404 rather than a login redirect — the gate itself lives in
// ProfileController::show() via ResolvesProfiles.
Route::get('/u/{username}', [ProfileController::class, 'show'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.show');
