<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Identity\Models\Passkey;
use Laravel\Passkeys\Actions\DeletePasskey;

/**
 * The one passkey endpoint this module answers itself (bugfix round
 * 2026-09-21, Task 16). Registration options, storing a new credential,
 * login options and the login itself stay with Fortify's routes and the
 * package's controllers: the ceremony is theirs, and the list on the
 * Security page comes from `SecurityController::edit()`.
 *
 * `IdentityServiceProvider` swaps this in for `passkey.destroy` in its
 * `booted()` route walk (the `register.store` idiom), because the package's
 * `destroy` answers 403 for a passkey that is not the caller's. That would
 * confirm the ULID exists on some other account; a session revocation in the
 * same situation says 404 (`SessionController`), and so does this.
 *
 * The relying party for every passkey route, this one included, is the
 * request host — `Http\Middleware\BindPasskeyRelyingParty`, applied in the
 * same route walk. Deleting does not run a ceremony, so the host does not
 * matter here; the middleware rides along so the seven routes are uniform.
 */
final class PasskeyController
{
    public function destroy(Request $request, Passkey $passkey, DeletePasskey $delete): RedirectResponse
    {
        $user = $request->user();

        abort_unless($passkey->user_id === $user->getKey(), 404);

        $delete($user, $passkey);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Passkey removed.')]);

        return to_route('security.edit');
    }
}
