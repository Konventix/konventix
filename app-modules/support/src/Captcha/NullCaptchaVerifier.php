<?php

declare(strict_types=1);

namespace Konventix\Support\Captcha;

use Illuminate\Http\Request;

/**
 * The only driver there is (`config/support.php` → `captcha.driver = null`):
 * every submission passes, and the honeypot and the rate limits do the
 * work (D-SUP-05).
 *
 * Bound unconditionally by `SupportServiceProvider::registerBindings()`
 * when no driver is named, so nothing in the request path ever has to ask
 * whether a captcha is configured.
 */
final class NullCaptchaVerifier implements CaptchaVerifier
{
    public function verify(Request $request): bool
    {
        return true;
    }
}
