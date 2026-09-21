<?php

declare(strict_types=1);

/*
 * Routes owned by the Registration module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:registration` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Registration\Http\Controllers\AttendeesController;
use Konventix\Registration\Http\Controllers\MyRegistrationsController;
use Konventix\Registration\Http\Controllers\RegistrationController;
use Konventix\Registration\Http\Controllers\WaitlistController;

// Who is coming (ADR-0064 §7): public, addressed by the event's slug like
// Event's own landing route, and resolved through this module's
// `EventSummary`. Deliberately NOT in the `auth` group — a hidden event or
// a switched-off list must read as 404, never as a login redirect; the
// controller asks a guest to sign in only for a `members` list on a visible
// event.
Route::get('/events/{slug}/attendees', [AttendeesController::class, 'index'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('registration.attendees.index');

// The public registration flow (SPEC.md, TODO.md). Both routes require an
// authenticated account — D-REG-08 (`docs/inventory/decision-log.md` Round
// 5): registrants must hold an account in Phase 1, guest registration is
// deferred. `{type}` binds a `RegistrationType` by its ULID primary key
// (ADR-0014); the controller checks it actually belongs to `{event_ulid}`.
Route::middleware(['auth'])->group(function (): void {
    // `require-consent` is Compliance's opt-in gate (its provider aliases it):
    // a registration is a contract, so it may not be entered by someone who
    // has not agreed to the brand's required policies. Registration applies
    // it here because Registration is the module that knows that — Compliance
    // wires nothing centrally (ADR-0003). `ensure-waiting-room` (ADR-0047
    // point 5) sits beside it: Platform owns the alias and the gate
    // contract, Commerce the queue — this module imports neither, it only
    // names the alias string, the same way it names `require-consent`.
    //
    // `require-contract-consent` (ADR-0066 §6.5, D-CPL-13/14) is the narrow
    // second alias, and it runs FIRST: the wider gate would otherwise
    // swallow the contract case and the prompt would be counted as
    // `reason: required` with no act named. Its parameter is the act's own
    // short label, a scalar this module supplies and Compliance only prints
    // — "Before you continue with your registration" (D-CPL-15). It sits on
    // the GET as well as the POST so `url.intended` has a page to come back
    // to; a POST never sets one.
    Route::middleware(['require-contract-consent:your registration', 'require-consent', 'ensure-waiting-room'])->group(function (): void {
        Route::get('/events/{event_ulid}/register/{type}', [RegistrationController::class, 'show'])
            ->name('registration.show');

        Route::post('/events/{event_ulid}/register/{type}', [RegistrationController::class, 'store'])
            ->name('registration.store');
    });

    // The registrant's own registrations, reached from the account menu.
    // No `require-consent` here: reading back what you already agreed to is
    // not a new contract, and a consent wall in front of it would lock a
    // registrant out of the record of their own registration.
    Route::get('/my/registrations', [MyRegistrationsController::class, 'index'])
        ->name('registration.mine.index');

    Route::get('/my/registrations/{registration}', [MyRegistrationsController::class, 'show'])
        ->name('registration.mine.show');

    // Waitlist offer claim (D-REG-12): the offer mail's temporary signed
    // link — signature expiry equals the offer's own; the controller
    // additionally checks the claimant IS the queued registrant.
    // Converting a waitlist offer concludes the registration the queue
    // promised, so it is a contract-creating act and carries the narrow
    // gate (D-CPL-14). After `signed`: an expired offer answers with that
    // fact rather than with a consent screen it would fail behind anyway.
    Route::post('/waitlist/{entry}/claim', [WaitlistController::class, 'claim'])
        ->middleware(['signed', 'require-contract-consent:claiming your waitlist offer'])
        ->name('registration.waitlist.claim');
});
