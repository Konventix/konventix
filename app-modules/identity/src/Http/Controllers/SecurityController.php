<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Data\PasswordUpdateData;
use Konventix\Identity\Data\SessionData;
use Konventix\Identity\Http\Requests\TwoFactorAuthenticationRequest;
use Konventix\Identity\Queries\ActiveSessionsQuery;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class SecurityController
{
    public function __construct(private readonly ActiveSessionsQuery $sessions) {}

    /**
     * Show the user's security settings page: password, two-factor
     * authentication (when the Fortify feature is enabled), and the session
     * list for this brand.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $request->ensureStateIsValid();

        $user = $request->user();

        $props = [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'sessions' => $this->sessions->for($user)
                ->map(fn ($session) => SessionData::fromModel($session, $request->session()->getId()))
                ->values(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Fortify::confirmsTwoFactorAuthentication();
            // Enabled generates the secret but `confirm: true` keeps
            // `hasEnabledTwoFactorAuthentication()` false until it is
            // confirmed — this is the signal the frontend uses to show the
            // QR code / confirmation-code step instead of the enable button.
            $props['twoFactorConfirming'] = $user->two_factor_secret !== null
                && $user->two_factor_confirmed_at === null;
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request, PasswordUpdateData $data): RedirectResponse
    {
        $request->user()->update([
            'password' => $data->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
