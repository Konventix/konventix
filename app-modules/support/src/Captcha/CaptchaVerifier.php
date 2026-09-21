<?php

declare(strict_types=1);

namespace Konventix\Support\Captcha;

use Illuminate\Http\Request;

/**
 * The abuse seam of D-SUP-05, declared on day one and deliberately empty.
 *
 * There is no captcha in this application and the operator decided not to
 * put one in front of "the purchase page is broken": a challenge costs
 * reports from exactly the people whose reports matter most, and the
 * honeypot plus the two rate limits (§6.4) are the trade that was taken
 * instead.
 *
 * What a seam buys that a `// TODO captcha` does not: turning one on later
 * is a `config/support.php` value and one new class implementing this
 * interface, never a change to the controller, the Action or the dialog.
 * The controller already calls `verify()` on every submission — the
 * decision point exists and is exercised by the suite, so it cannot rot.
 */
interface CaptchaVerifier
{
    /**
     * Whether this submission may proceed. A false answer is a 422 on the
     * captcha field, never a silent drop — a human who failed a challenge
     * has to be told, unlike a bot caught by the honeypot.
     */
    public function verify(Request $request): bool;
}
