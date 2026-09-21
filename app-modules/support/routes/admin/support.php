<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Support\Http\Controllers\SupportRequestController;

/*
 * Routes owned by the Support module on the operator console.
 *
 * `ModuleServiceProvider::registerRoutes()` wraps this directory in `web`,
 * the operator-host guard, `auth` and `can:platform.access-admin`, and
 * prefixes it with `admin` — so the declaration below answers at
 * `/admin/support/requests`. Declare bare routes here: do not re-apply any
 * of that, and never require this file from anywhere else.
 *
 * Same controller and same Action as the brand host (design §6.4). The only
 * difference is what the request resolves: no brand on this host
 * (ADR-0029), so the row's `filed_on_brand_id` is null and the report is
 * console-filed — which is a fact about provenance, not a second code path.
 *
 * ADR-0063 is why this file exists at all: Support is not gateable, so the
 * console can carry the launcher, which a per-brand Pennant gate could
 * never allow on a host that resolves no brand.
 */
Route::post('/support/requests', [SupportRequestController::class, 'store'])
    ->middleware('throttle:support-report')
    ->name('admin.support.requests.store');
