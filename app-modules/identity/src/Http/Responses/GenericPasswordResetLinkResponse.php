<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0016/#18: one answer for `POST /forgot-password`, whether or not the
 * address has an account.
 *
 * Fortify's own two responses read differently on purpose in the stock
 * package — `FailedPasswordResetLinkRequestResponse` puts the broker's
 * `passwords.user` status on the `email` field (a `back()->withErrors()`, or
 * a 422 `ValidationException` for a JSON caller) while
 * `SuccessfulPasswordResetLinkRequestResponse` flashes `passwords.sent` as a
 * plain `status` (or a 200 for JSON) — which is exactly the "already taken"
 * validation error ADR-0016 forbids for a form that accepts an email address.
 * Binding both contracts to this one class in
 * `IdentityServiceProvider::configureFortifyResponses()` makes the two
 * branches produce the identical response: same status code, same shape,
 * same message, whether `Illuminate\Auth\Passwords\PasswordBroker::
 * sendResetLink()` returned `RESET_LINK_SENT`, `INVALID_USER` or
 * `RESET_THROTTLED`. No constructor: `PasswordResetLinkController::store()`
 * passes `app()->make($class, ['status' => $status])`, but a parameter the
 * container's `make()` receives and the constructor does not declare is
 * simply never consulted — declaring one just to ignore it is the leak
 * dressed up as an API, and PHPStan (rightly) refuses an unused one.
 *
 * The timing channel `PasswordBroker::sendResetLink()` might otherwise open
 * (hashing/creating a token for a real user vs. an immediate `INVALID_USER`
 * return) is already closed upstream — the broker wraps its whole body in
 * `Illuminate\Support\Timebox`, a fixed 200ms floor, for exactly this reason.
 * Nothing here needs to repeat that.
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponseContract, SuccessfulPasswordResetLinkRequestResponseContract
{
    private const string MESSAGE = 'If an account exists for that email address, we have emailed a password reset link to it.';

    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MESSAGE], 200)
            : back()->with('status', self::MESSAGE);
    }
}
