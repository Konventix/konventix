<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\RegistrationFormData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Exceptions\QuotaExhaustedException;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Laravel\Pennant\Feature;

/**
 * The public registration flow (SPEC.md, TODO.md "Add the public
 * registration form"). Translates HTTP to `Data`/`Actions` and back —
 * business logic lives entirely in `SubmitRegistration`.
 *
 * Both routes require an authenticated account (D-REG-08 —
 * `docs/inventory/decision-log.md` Round 5); see `routes/web/registration.php`.
 */
final class RegistrationController
{
    public function show(Request $request, string $event_ulid, RegistrationType $type): Response
    {
        $invite = $request->string('invite')->toString() ?: null;
        $this->guardReachable($event_ulid, $type, $invite);

        return Inertia::render('registration/Register', [
            ...RegistrationFormData::fromModel($type)->toArray(),
            'inviteToken' => $invite,
        ]);
    }

    public function store(
        Request $request,
        string $event_ulid,
        RegistrationType $type,
        SubmitRegistrationData $data,
        SubmitRegistration $action,
        JoinWaitlist $joinWaitlist,
        BrandContext $brandContext,
    ): RedirectResponse {
        $invite = $request->string('inviteToken')->toString() ?: null;
        $this->guardReachable($event_ulid, $type, $invite);

        // The `auth` middleware guarantees a logged-in user (D-REG-08);
        // auth()->id() is typed int|string|null generically because the
        // contract does not know every guard uses ULID string keys.
        $registrantUserId = (string) auth()->id();

        try {
            $registration = $action->handle($type, $registrantUserId, $data, invitationToken: $invite);
        } catch (QuotaExhaustedException) {
            // D-REG-12: an exhausted quota queues the whole submission
            // instead of failing it.
            $joinWaitlist->handle($type, $registrantUserId, $data);

            Inertia::flash('toast', ['type' => 'info', 'message' => __('This registration is currently full — you are on the waitlist and will be notified when a spot opens.')]);

            return $this->waitlistLanding($type);
        }

        // ADR-0047 point 3: with the shop feature on, an instant settlement
        // just landed in the registrant's cart instead of an order — send
        // them there to check out. `commerce.cart.show` is Commerce's own
        // route; Route::has() keeps this module free of any import from it.
        if (
            $type->settlement === RegistrationSettlement::Instant
            && $this->settlementRequested($type, $registration)
            && ($brand = $brandContext->current()) !== null
            && Feature::for($brand)->active('commerce:shop')
            && Route::has('commerce.cart.show')
        ) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to your cart — check out when you are ready.')]);

            return to_route('commerce.cart.show');
        }

        // Shop off (the per-brand default): the same settlement request
        // became a direct order, and Commerce's pay page for it
        // (`commerce.pay.show`, ADR-0039) is where the money is paid. The
        // form itself is a full page, so answering with `registration.show`
        // re-rendered an empty form under a toast and the payer never saw
        // their pay page. Should the order not exist after all, that
        // controller hands off to the cart or to `registration.mine.show`
        // itself; here again only the route *name* crosses the module line.
        if (
            $type->settlement === RegistrationSettlement::Instant
            && $this->settlementRequested($type, $registration)
            && Route::has('commerce.pay.show')
        ) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration submitted — complete your payment to confirm it.')]);

            return to_route('commerce.pay.show', ['registration' => $registration->getKey()]);
        }

        // Everything else — free, invoice-settled, external, or an instant
        // type whose settlement request waits for approval — is done for
        // now, and the registrant's own record is the page that says what
        // happens next (Commerce's `registration.mine.show` slot renders
        // the invoice or the cart line once one exists). Never back to the
        // form: a successful submit leaves the page it was submitted from.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration submitted.')]);

        return to_route('registration.mine.show', ['registration' => $registration->getKey()]);
    }

    /**
     * Where a waitlisted submission lands (D-REG-12). `/my/registrations`
     * lists `Registration` rows only, and a `WaitlistEntry` is not one, so
     * that page would answer "you have not registered for anything" to
     * someone who just queued. The event's landing page is the surface
     * that still shows the offer they queued for; its route and slug are
     * Event's, reached through this module's own `EventSummary` read model
     * (ADR-0004) and `Route::has()`, as everywhere else here. Without a
     * projected summary the list of their registrations is the fallback —
     * never the form they just submitted.
     */
    private function waitlistLanding(RegistrationType $type): RedirectResponse
    {
        $slug = Route::has('event.show')
            ? EventSummary::query()->where('event_id', $type->event_id)->value('slug')
            : null;

        if (is_string($slug) && $slug !== '') {
            return to_route('event.show', ['event' => $slug]);
        }

        return to_route('registration.mine.index');
    }

    /**
     * Whether this submission has already sent the registration's
     * settlement request out — so its money is now somewhere it can be
     * paid, the cart (ADR-0047 point 3) or a direct order (ADR-0039) — and
     * the registrant can therefore be taken straight there.
     *
     * The confirmation gate (ADR-0005) does not decide *where* the money is
     * paid: `CreateOrderOnSettlementRequested` routes every `instant`
     * settlement into the cart while `commerce:shop` is on and into a
     * direct order otherwise, whatever the gate. It decides *when* the
     * settlement request goes out, and so whether a cart line or an order
     * exists yet at the moment this response is written. Per gate, for
     * `settlement: instant`:
     *
     * - `on_payment` — `SubmitRegistration` emits the settlement request at
     *   submission (nothing can confirm before the money is payable), so the
     *   line or order exists: payable now, redirect to the cart or the pay
     *   page.
     * - `on_submit` with `approval: none` — `SubmitRegistration` confirms in
     *   the same transaction and `ConfirmRegistration` emits the request, so
     *   the line or order exists too: payable now, same redirect. This is
     *   the case the operator hit on dev — the line was created and nothing
     *   ever said so (#210).
     * - `on_submit` with `approval: required` — approval is a precondition,
     *   nothing confirms yet, no request went out and nothing is payable:
     *   the registrant's own record (`registration.mine.show`).
     * - `on_approval` — the request goes out when an organizer approves and
     *   `ConfirmRegistration` runs, which is not now: the registrant's own
     *   record as well. They reach the cart or the pay page from there once
     *   the line or order exists (Commerce's `registration.mine.show` slot
     *   renders it).
     *
     * Expressed as the state the two emission points leave behind rather
     * than as a second copy of their gate arithmetic: a confirmed
     * registration has been through `ConfirmRegistration`, and an
     * `on_payment` one was served by `SubmitRegistration` itself.
     */
    private function settlementRequested(RegistrationType $type, Registration $registration): bool
    {
        return $type->confirmation_gate === ConfirmationGate::OnPayment
            || $registration->status === RegistrationStatus::Confirmed;
    }

    /**
     * The type must belong to the event in the URL; non-public types need
     * a usable invitation token (Round 6 D-COM-02). This is the reachability
     * check for the page — the burn-a-use enforcement lives in
     * `SubmitRegistration::guardVisibility()` under lock.
     */
    private function guardReachable(string $eventUlid, RegistrationType $type, ?string $invite): void
    {
        abort_unless($type->event_id === $eventUlid, 404);

        if ($type->visibility === RegistrationVisibility::Public) {
            return;
        }

        $token = $invite === null ? null : InvitationToken::query()
            ->where('registration_type_id', $type->id)
            ->where('token', $invite)
            ->first();

        abort_unless($token !== null && $token->isUsable(), 403);
    }
}
