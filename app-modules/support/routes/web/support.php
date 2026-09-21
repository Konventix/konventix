<?php

declare(strict_types=1);

/*
 * Routes owned by the Support module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:support` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Support\Http\Controllers\GuestSupportRequestController;
use Konventix\Support\Http\Controllers\KnownIssueController;
use Konventix\Support\Http\Controllers\MySupportRequestsController;
use Konventix\Support\Http\Controllers\SupportRequestController;

/*
 * The report channel (design §6.4, S1). Open to guests: filing a report
 * needs no account and no capability — a channel that required either would
 * be a channel the people who find the bugs cannot reach (ADR-0063).
 *
 * `throttle:support-report` is the per-account/per-IP budget declared in
 * `SupportServiceProvider::configureRateLimiting()`; the per-address one
 * lives in the controller, because only the controller has read the address.
 */
Route::post('/support/requests', [SupportRequestController::class, 'store'])
    ->middleware('throttle:support-report')
    ->name('support.requests.store');

/*
 * The known-issue banner's source (design §9.4). Open to everybody, because
 * the dialog that asks for it is: a visitor about to report a problem the
 * operator already knows about has not signed in, and telling them before
 * they type is the whole point.
 *
 * Cached for sixty seconds in `Queries\KnownIssues`, so no throttle stands
 * in front of it — every answer is a cache read of a handful of rows, and a
 * limiter would only add state to something that has none.
 */
Route::get('/support/known-issues', KnownIssueController::class)
    ->name('support.known-issues');

/*
 * The signed-in reporter's own reports (design §8.1, S3).
 *
 * `auth` and nothing else. No `require-consent`: reading back a report you
 * filed yourself is not a new contract, which is the reason Registration
 * gives on `/my/registrations` and Commerce on `/my/orders`. No capability
 * either — ownership is checked in the controller's query and everything
 * that is not yours is a 404, because a 403 on an eight-character reference
 * is an answer about whether it exists.
 *
 * The parameter is named `supportRequest` on every Support route, including
 * these, so the console's dry-run binder and these pages' resolve through
 * one name (`tests/Feature/DryRunTest.php`).
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/support-requests', [MySupportRequestsController::class, 'index'])
        ->name('support.requests.mine.index');
    Route::get('/my/support-requests/{supportRequest}', [MySupportRequestsController::class, 'show'])
        ->whereUlid('supportRequest')
        ->name('support.requests.mine.show');
    Route::post('/my/support-requests/{supportRequest}/reply', [MySupportRequestsController::class, 'reply'])
        ->whereUlid('supportRequest')
        ->name('support.requests.mine.reply');
});

/*
 * A guest's own report, through the 30-day signed link in their receipt
 * (design §8.2, S3).
 *
 * The middleware order is the mechanism, not a detail:
 * `support-link-expired` sits **outside** `signed`, so that
 * `ValidateSignature`'s exception travels back up into it and becomes the
 * re-issue page instead of a bare 403 (see the middleware's docblock). A
 * request with a bad signature still never reaches the controller.
 *
 * `signed` rather than `signed:relative` because the link is minted
 * absolute on the brand host the report was filed on, which is the host the
 * receipt mail sends the person to.
 */
Route::middleware(['support-link-expired', 'signed'])->group(function (): void {
    Route::get('/support/requests/{supportRequest}', [GuestSupportRequestController::class, 'show'])
        ->whereUlid('supportRequest')
        ->name('support.requests.show');
    Route::post('/support/requests/{supportRequest}/reply', [GuestSupportRequestController::class, 'reply'])
        ->whereUlid('supportRequest')
        ->name('support.requests.reply');
});

/*
 * "Send me a new link" (design §8.2).
 *
 * Unsigned by necessity — the person pressing it is holding a signature
 * that no longer verifies — and therefore the most carefully limited
 * endpoint in the module: it takes a reference and nothing else, mails only
 * the address already on the row, answers identically whatever it was
 * given, and is capped at three an hour per reference by the named
 * `support-link` limiter.
 *
 * No `{supportRequest}` in the path: the form's whole input is a reference,
 * and a reference that names nothing is a perfectly ordinary thing for a
 * person to type. A route parameter would have had to 404 on it, which is
 * the one answer this endpoint must never give.
 */
Route::post('/support/requests/link', [GuestSupportRequestController::class, 'reissue'])
    ->middleware('throttle:support-link')
    ->name('support.requests.link');
