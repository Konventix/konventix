<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Identity\Sessions\SessionRepository;

/**
 * Revocation for the session list on the Security settings page (SPEC.md
 * "Owns" — Sessions). Reads live in `SecurityController::edit()`; this
 * controller only ever revokes, and only ever the acting user's own —
 * `Sessions\SessionRepository` takes the user on every call and filters by
 * it — so there is no capability check beyond that, per SPEC.md's "acting
 * on one's own account is a policy question, not a capability".
 *
 * Which store is behind the contract is the binding's business (D-IDP-17):
 * `sessions` rows on the database driver, the per-user index
 * `Sessions\IndexedRedisSessionHandler` writes on redis, nothing at all on
 * array/file/cookie — where the revocations are honest no-ops because there
 * is nothing stored to revoke.
 */
final class SessionController
{
    public function __construct(private readonly SessionRepository $sessions) {}

    /**
     * Revoke a single session. Gated by the `password.confirm` middleware on
     * the route (routes/web/identity.php) rather than an inline password
     * field, unlike `ProfileController::destroy()` — kept simple per the
     * task brief rather than matching that pattern.
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        $this->sessions->revoke($request->user(), $session);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return to_route('security.edit');
    }

    /**
     * Revoke every session for the account except the one making this
     * request — a direct revocation in the store the driver uses (SPEC.md's
     * "Sessions... and their revocation") rather than Fortify's
     * `logoutOtherDevices()`, which rotates the remember token and re-hashes
     * the password instead of touching what this page lists, so it would
     * leave the list showing sessions that no longer work.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $this->sessions->revokeOthers($request->user(), $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Other sessions revoked.')]);

        return to_route('security.edit');
    }
}
