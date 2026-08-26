<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Konventix\Platform\Cookies\CookiePreferences;
use Konventix\Platform\Data\CookiePreferencesData;

/**
 * The cookie-settings dialog's one write. Withdraws consent as easily as it
 * was given (Art. 7(3) GDPR): disabling functional storage does not just
 * stop future writes, it clears what is already on the device.
 */
final class CookiePreferencesController
{
    public function __invoke(CookiePreferencesData $data): RedirectResponse
    {
        $response = back()->withCookie(cookie(
            name: CookiePreferences::COOKIE,
            value: CookiePreferences::value($data->functional),
            minutes: CookiePreferences::LIFETIME_MINUTES,
            path: '/',
            secure: null,
            httpOnly: false,   // the client reads it synchronously on first paint
            sameSite: 'lax',
        ));

        if (! $data->functional) {
            foreach (CookiePreferences::FUNCTIONAL_COOKIES as $name) {
                $response->withCookie(Cookie::forget($name));
            }
        }

        return $response;
    }
}
