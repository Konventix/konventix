<?php

declare(strict_types=1);

/*
 * Routes owned by the Agenda module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:agenda` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Agenda\Http\Controllers\ProgrammeController;

/*
 * The public programme (design §6). Genuinely public: no auth, no
 * capability — a programme is an announcement, and gating it behind an
 * account would defeat the point of publishing one. The only gate it
 * passes is `ensure-module:agenda`, which the provider wraps this whole
 * file in.
 *
 * `{slug}` and not `{event:slug}`: implicit binding would need Event's
 * Eloquent model, which this module may not import (ADR-0004). The slug is
 * resolved against Agenda's own `agenda_event_summaries` read model
 * instead. The pattern is the one `Str::slug()` produces, so a request for
 * anything else never reaches the controller.
 */
Route::get('/events/{slug}/programm', ProgrammeController::class)
    ->where('slug', '[a-z0-9-]+')
    ->name('agenda.programme');
