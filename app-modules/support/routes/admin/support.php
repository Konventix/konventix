<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Support\Http\Controllers\Admin\SupportRequestAdminController;
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

/*
 * The console inbox and one report (design §9).
 *
 * `/admin/support-requests` rather than `/admin/support/requests`: the
 * inbox is a list of reports, not a second endpoint of the report channel
 * above, and the URL is the one an operator reads in an alert annotation
 * and a notice mail. The route *names* still share the module's prefix, so
 * Wayfinder keeps every Support console helper in one file and
 * `Links\SupportLinks` can name `admin.support.requests.show`.
 *
 * Reading is `support.view-requests` and every write is
 * `support.triage-requests`; both are authorized in the controller rather
 * than as route middleware, because the detail page needs a third
 * capability (`support.view-reporter-contact`) for one field of the same
 * response, and splitting the three across two mechanisms would hide that.
 */
Route::get('/support-requests', [SupportRequestAdminController::class, 'index'])
    ->name('admin.support.requests.index');
Route::get('/support-requests/{supportRequest}', [SupportRequestAdminController::class, 'show'])
    ->name('admin.support.requests.show');
Route::patch('/support-requests/{supportRequest}/state', [SupportRequestAdminController::class, 'updateState'])
    ->name('admin.support.requests.state');
Route::patch('/support-requests/{supportRequest}/assignee', [SupportRequestAdminController::class, 'updateAssignee'])
    ->name('admin.support.requests.assignee');
Route::patch('/support-requests/{supportRequest}/known-issue', [SupportRequestAdminController::class, 'updateKnownIssue'])
    ->name('admin.support.requests.known-issue');
Route::patch('/support-requests/{supportRequest}/external-reference', [SupportRequestAdminController::class, 'updateExternalReference'])
    ->name('admin.support.requests.external-reference');
Route::post('/support-requests/{supportRequest}/messages', [SupportRequestAdminController::class, 'storeMessage'])
    ->name('admin.support.requests.messages.store');
