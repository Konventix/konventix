<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\RegistrationFormData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Exceptions\QuotaExhaustedException;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\RegistrationType;

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
    ): RedirectResponse {
        $invite = $request->string('inviteToken')->toString() ?: null;
        $this->guardReachable($event_ulid, $type, $invite);

        // The `auth` middleware guarantees a logged-in user (D-REG-08);
        // auth()->id() is typed int|string|null generically because the
        // contract does not know every guard uses ULID string keys.
        $registrantUserId = (string) auth()->id();

        try {
            $action->handle($type, $registrantUserId, $data, invitationToken: $invite);
        } catch (QuotaExhaustedException) {
            // D-REG-12: an exhausted quota queues the whole submission
            // instead of failing it.
            $joinWaitlist->handle($type, $registrantUserId, $data);

            Inertia::flash('toast', ['type' => 'info', 'message' => __('This registration is currently full — you are on the waitlist and will be notified when a spot opens.')]);

            return to_route('registration.show', ['event_ulid' => $event_ulid, 'type' => $type]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration submitted.')]);

        return to_route('registration.show', ['event_ulid' => $event_ulid, 'type' => $type]);
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
