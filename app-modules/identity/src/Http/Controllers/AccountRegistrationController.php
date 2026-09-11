<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Konventix\Identity\Actions\CreateNewUser;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

/**
 * `POST /register` — ADR-0016, GitLab #18: `IdentityServiceProvider::
 * throttleUnhookedFortifyRoutes()` swaps the `register.store` route Fortify
 * itself registers onto this controller, in place of its stock
 * `RegisteredUserController`. `Features::registration()` stays enabled in
 * `config/fortify.php` throughout — the route, its name, its throttle
 * middleware and `Fortify::registerView()`'s GET page are all untouched;
 * only the `store()` action changes hands, because Fortify's own controller
 * cannot be told "this call succeeded, but there is no user to log in."
 *
 * That is the whole reason this class exists rather than a `bind()` on
 * `CreatesNewUsers`/`RegisterResponse`: `RegisteredUserController::store()`
 * unconditionally does `event(new Registered($user = $creator->create(...)))`
 * then `$this->guard->login($user, ...)` — if `CreateNewUser::create()`
 * answered a duplicate address by returning the *existing* account so that
 * line would not blow up, the requester would be logged into a stranger's
 * session. Returning `null` instead needs a caller that can take "no
 * account" for an answer, which the stock controller is not.
 *
 * The rest is a deliberately close copy of `RegisteredUserController::
 * store()`: same `lowercase_usernames` handling, same `RegisterResponse`
 * contract for the final answer, so a genuine signup renders byte-identical
 * to what Fortify would have produced. The one behavioural difference is
 * conditioning `Registered`/`$guard->login()` on `$user !== null`, and
 * moving `$request->session()->regenerate()` outside that condition —
 * unconditional, so the session cookie rotates the same way whether or not
 * an account was actually created, which is one fewer signal a script
 * comparing two responses byte-for-byte could still read.
 */
final class AccountRegistrationController
{
    public function store(Request $request, CreateNewUser $creator, StatefulGuard $guard): RegisterResponse
    {
        if (config('fortify.lowercase_usernames') && $request->has(Fortify::username())) {
            $request->merge([
                Fortify::username() => mb_strtolower((string) $request->{Fortify::username()}),
            ]);
        }

        $user = $creator->create($request->all());

        if ($user !== null) {
            event(new Registered($user));

            $guard->login($user, $request->boolean('remember'));
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return app(RegisterResponse::class);
    }
}
