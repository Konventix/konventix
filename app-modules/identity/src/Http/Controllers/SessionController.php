<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Identity\Models\Session;

/**
 * Revocation for the session list on the Security settings page (SPEC.md
 * "Owns" — Sessions). Reads live in `Queries\ActiveSessionsQuery`, called from
 * `SecurityController::edit()`; this controller only ever deletes rows, and
 * only ever the acting user's own (`where('user_id', ...)`) — there is no
 * capability check beyond that, per SPEC.md's "acting on one's own account is
 * a policy question, not a capability".
 */
final class SessionController
{
    /**
     * Revoke a single session. Gated by the `password.confirm` middleware on
     * the route (routes/web/identity.php) rather than an inline password
     * field, unlike `ProfileController::destroy()` — kept simple per the
     * task brief rather than matching that pattern.
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        Session::query()
            ->whereKey($session)
            ->where('user_id', $request->user()->getKey())
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return to_route('security.edit');
    }

    /**
     * Revoke every session for the account except the one making this
     * request — a direct delete (SPEC.md's "Sessions... and their
     * revocation") rather than Fortify's `logoutOtherDevices()`, which
     * rotates the remember token and re-hashes the password instead of
     * touching the `sessions` table rows this page lists, so it would leave
     * the list showing sessions that no longer work.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        Session::query()
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Other sessions revoked.')]);

        return to_route('security.edit');
    }
}
