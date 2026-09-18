<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Konventix\Identity\Actions\SetAccountEmail;
use Konventix\Identity\Data\SetAccountEmailData;
use Konventix\Identity\Models\User;

/**
 * The POST behind the "set your email address" form that the verification
 * notice shows to a placeholder account (#293). The page itself is Fortify's
 * `verification.notice`, branched in `IdentityServiceProvider::
 * configureViews()`; only the submission needs a route of its own.
 */
final class AccountEmailController
{
    public function store(Request $request, SetAccountEmail $setAccountEmail): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Not a second way to change an address: an account that already has
        // a real one edits it on the profile form, where the change sits
        // beside the rest of the profile and its own guards.
        abort_unless($user->hasPlaceholderEmail(), 403);

        $data = SetAccountEmailData::validateAndCreate($request->all());

        $setAccountEmail->handle($user, $data->email);

        return redirect()->route('verification.notice')->with('status', 'verification-link-sent');
    }
}
