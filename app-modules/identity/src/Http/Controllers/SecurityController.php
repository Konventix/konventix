<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Data\PasskeyData;
use Konventix\Identity\Data\PasswordUpdateData;
use Konventix\Identity\Data\SessionData;
use Konventix\Identity\Http\Requests\TwoFactorAuthenticationRequest;
use Konventix\Identity\Sessions\SessionRepository;
use Konventix\Identity\Sessions\SessionSummary;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class SecurityController
{
    /**
     * The session list comes from `Sessions\SessionRepository`, not from the
     * `sessions` table directly (D-IDP-17): which store is behind it is the
     * binding's business, and this page only asks whether there is one
     * (`available()`) and what is in it.
     */
    public function __construct(private readonly SessionRepository $sessions) {}

    /**
     * Show the user's security settings page: password, two-factor
     * authentication and passkeys (each when its Fortify feature is
     * enabled), and the session list for this brand. `hasPassword` tells the form whether it is
     * changing a password or setting the first one — a Steam-only account
     * has none, and `PasswordUpdateData` refuses `current_password` for it.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $request->ensureStateIsValid();

        $user = $request->user();

        $props = [
            'hasPassword' => $user->hasPassword(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'sessions' => collect($this->sessions->activeFor($user))
                ->map(fn (SessionSummary $session) => SessionData::fromSummary($session, $request->session()->getId()))
                ->values(),
            // The database driver keeps rows, and the redis driver keeps the
            // per-user index `Sessions\IndexedRedisSessionHandler` writes; on
            // array, file or cookie there is nothing to show or to revoke, and
            // the page says so rather than presenting an empty list as "no
            // other sessions".
            'sessionsAvailable' => $this->sessions->available(),
            'canManagePasskeys' => Features::canManagePasskeys(),
        ];

        if (Features::canManagePasskeys()) {
            // The list lives here rather than on a route of its own: the
            // package exposes no index, and this page is the only reader.
            $props['passkeys'] = $user->passkeys()
                ->orderBy('created_at')
                ->get()
                ->map(fn ($passkey) => PasskeyData::fromModel($passkey))
                ->values();
        }

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
        $user = $request->user();
        $hadPassword = $user->hasPassword();

        $user->update([
            'password' => $data->password,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $hadPassword ? __('Password updated.') : __('Password set.'),
        ]);

        return back();
    }
}
