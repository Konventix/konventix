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

            return to_route('registration.show', ['event_ulid' => $event_ulid, 'type' => $type]);
        }

        // ADR-0047 point 3: with the shop feature on, an instant settlement
        // just landed in the registrant's cart instead of an order — send
        // them there to check out. `commerce.cart.show` is Commerce's own
        // route; Route::has() keeps this module free of any import from it.
        if (
            $type->settlement === RegistrationSettlement::Instant
            && $this->settlementReachedTheCart($type, $registration)
            && ($brand = $brandContext->current()) !== null
            && Feature::for($brand)->active('commerce:shop')
            && Route::has('commerce.cart.show')
        ) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to your cart — check out when you are ready.')]);

            return to_route('commerce.cart.show');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration submitted.')]);

        return to_route('registration.show', ['event_ulid' => $event_ulid, 'type' => $type]);
    }

    /**
     * Whether this submission has already put the registration's money
     * where it is paid — the cart (ADR-0047 point 3) — and the registrant
     * can therefore be taken straight there.
     *
     * The confirmation gate (ADR-0005) does not decide *whether* the cart is
     * the payment place: `CreateOrderOnSettlementRequested` routes every
     * `instant` settlement into the cart while `commerce:shop` is on,
     * whatever the gate. It decides *when* the settlement request goes out,
     * and so whether a cart line exists yet at the moment this response is
     * written. Per gate, for `settlement: instant`:
     *
     * - `on_payment` — `SubmitRegistration` emits the settlement request at
     *   submission (nothing can confirm before the money is payable), so the
     *   line exists: payable now, redirect to the cart.
     * - `on_submit` with `approval: none` — `SubmitRegistration` confirms in
     *   the same transaction and `ConfirmRegistration` emits the request, so
     *   the line exists too: payable now, redirect to the cart. This is the
     *   case the operator hit on dev — the line was created and nothing ever
     *   said so (#210).
     * - `on_submit` with `approval: required` — approval is a precondition,
     *   nothing confirms yet, no request went out and no line exists: stay
     *   on the registration page.
     * - `on_approval` — the request goes out when an organizer approves and
     *   `ConfirmRegistration` runs, which is not now: stay on the
     *   registration page. The registrant reaches the cart from
     *   `registration.mine.show` once the line exists (Commerce's
     *   `registration.mine.show` slot renders it).
     *
     * Expressed as the state the two emission points leave behind rather
     * than as a second copy of their gate arithmetic: a confirmed
     * registration has been through `ConfirmRegistration`, and an
     * `on_payment` one was served by `SubmitRegistration` itself.
     */
    private function settlementReachedTheCart(RegistrationType $type, Registration $registration): bool
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
