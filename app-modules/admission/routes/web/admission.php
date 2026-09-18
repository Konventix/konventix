<?php

declare(strict_types=1);

/*
 * Routes owned by the Admission module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:admission` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Admission\Http\Controllers\ClaimController;
use Konventix\Admission\Http\Controllers\EntranceClientAssetController;
use Konventix\Admission\Http\Controllers\HolderController;
use Konventix\Admission\Http\Controllers\InviteController;
use Konventix\Admission\Http\Controllers\MyTicketsController;
use Konventix\Admission\Http\Controllers\TicketDownloadController;
use Konventix\Admission\Http\Middleware\RecordTicketRenderMetrics;

/*
 * Claiming a holder-less admission (ADR-0032). Two guards stack, exactly as
 * Registration's waitlist claim does it: `signed` proves the URL was minted
 * by us and has not expired, `auth` proves a real account is attaching itself
 * (D-REG-08). Neither alone is enough — a signed link with no account would
 * assign an admission to nobody.
 */
Route::middleware(['auth'])->group(function (): void {
    // The landing page (#292 §4.3) on the same path as the POST, so one
    // signed URL serves both: `signed` covers path and query, not method.
    Route::get('/admissions/claim/{link}', [ClaimController::class, 'show'])
        ->middleware('signed')
        ->name('admission.claim.show');

    Route::post('/admissions/claim/{link}', [ClaimController::class, 'store'])
        ->middleware('signed')
        ->name('admission.claim');
});

/*
 * The offline entrance client's shell (ADR-0002's carve-out, ADR-0033's trust
 * model). Unauthenticated on purpose: the page is a static bundle carrying no
 * data, and the *device* authenticates itself to `/api/entrance/*` with its
 * own bearer token, which the operator enters once at enrolment. Requiring a
 * session here would defeat the point — a door tablet has no user to log in.
 */
Route::view('/entrance', 'admission::entrance.client')->name('admission.entrance-client');

/*
 * The service worker and manifest are served from the site root rather than
 * from /build, because a worker may only control URLs at or below its own
 * path — one at /build/entrance-sw.js could never claim /entrance.
 */
Route::get('/entrance-sw.js', [EntranceClientAssetController::class, 'serviceWorker']);
Route::get('/entrance.webmanifest', [EntranceClientAssetController::class, 'manifest']);

/*
 * The buyer's combined ticket file (design §4.1, §7): every admission the
 * registration granted, one page each, ordered by creation. Authorised
 * entirely on `admissions.buyer_user_id` — never a Registration table
 * (ADR-0004) — which is why `{registration}` stays a plain ULID rather than
 * a route-model binding, exactly as Resources' seat picker does it.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/registrations/{registration}/ticket', [TicketDownloadController::class, 'buyer'])
        ->middleware(RecordTicketRenderMetrics::class)
        ->name('admission.tickets.buyer');
});

/*
 * The holder's own single-page file (design §4.1, §7): the counterpart to
 * the route above, and — for a staff admission, which carries no
 * `registration_id` at all (D-STF-01) — the only route that can ever serve
 * its ticket.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/admissions/{admission}/ticket', [TicketDownloadController::class, 'holder'])
        ->middleware(RecordTicketRenderMetrics::class)
        ->name('admission.tickets.holder');
});

/*
 * The holder's own list (design §4.1, §7): every admission whose
 * `holder_user_id` is the signed-in account, whichever registration — if
 * any — produced it. An admission nobody has claimed appears here for
 * nobody; its ticket reaches its buyer through the buyer's own route
 * instead.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/tickets', [MyTicketsController::class, 'index'])
        ->name('admission.tickets.mine');
});

/*
 * The ticket manager's actions (#292 §4.3, #16 points 1 and 3): the buyer
 * of a registration names themselves the holder of one of its admissions,
 * takes one back, invites somebody by address or link, or withdraws the
 * invitation. Ownership (`buyer_user_id`) is the whole authorisation, checked
 * inline in the controllers. The invite routes send mail, so they are
 * throttled a little tighter than the page they sit on.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::post('/my/admissions/{admission}/holder', [HolderController::class, 'store'])
        ->name('admission.holder.store');
    Route::delete('/my/admissions/{admission}/holder', [HolderController::class, 'destroy'])
        ->name('admission.holder.destroy');

    Route::post('/my/admissions/{admission}/invite', [InviteController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('admission.invite.store');
    Route::delete('/my/admissions/{admission}/invite', [InviteController::class, 'destroy'])
        ->middleware('throttle:20,1')
        ->name('admission.invite.destroy');
});
