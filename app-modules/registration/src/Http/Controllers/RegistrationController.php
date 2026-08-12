<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\RegistrationFormData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationVisibility;
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
    public function show(string $event_ulid, RegistrationType $type): Response
    {
        $this->guardReachable($event_ulid, $type);

        return Inertia::render('registration/Register', RegistrationFormData::fromModel($type)->toArray());
    }

    public function store(
        string $event_ulid,
        RegistrationType $type,
        SubmitRegistrationData $data,
        SubmitRegistration $action,
    ): RedirectResponse {
        $this->guardReachable($event_ulid, $type);

        // The `auth` middleware guarantees a logged-in user (D-REG-08);
        // auth()->id() is typed int|string|null generically because the
        // contract does not know every guard uses ULID string keys.
        $registrantUserId = auth()->id();

        $action->handle($type, $registrantUserId !== null ? (string) $registrantUserId : null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration submitted.')]);

        return to_route('registration.show', ['event_ulid' => $event_ulid, 'type' => $type]);
    }

    /**
     * The type must belong to the event in the URL, and — since invitation
     * and link tokens are not built yet (TODO.md Phase 2 backlog) — must be
     * publicly visible.
     */
    private function guardReachable(string $eventUlid, RegistrationType $type): void
    {
        abort_unless($type->event_id === $eventUlid, 404);
        abort_unless($type->visibility === RegistrationVisibility::Public, 403);
    }
}
