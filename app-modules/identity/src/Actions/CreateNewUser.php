<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Timebox;
use Konventix\Identity\Data\RegisterData;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Mail\AuthMailer;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Validates and creates the account behind `POST /register`, or —
 * ADR-0016, GitLab #18 — decides that no account should exist and answers
 * as if one had been created anyway.
 *
 * `AccountRegistrationController::store()` is what calls this, not Fortify's
 * stock `RegisteredUserController`: that controller unconditionally logs in
 * whatever `CreatesNewUsers::create()` returns, which is exactly wrong for a
 * duplicate address — there is no account of the requester's to log into.
 * Returning `null` is the signal: the controller fires `Registered` and
 * calls `$guard->login()` only for a real `User`, and either way returns the
 * identical `RegisterResponse`, so the requester cannot tell the two apart
 * (`AccountRegistrationController`'s own docblock has the response side).
 *
 * Validation stays `RegisterData::validateAndCreate()` rather than a manual
 * `Validator::make()` (contrast `ResetUserPassword`, which predates this
 * module's Data objects) so the registration form has one source of
 * validation rules, Inertia props and TypeScript type (ADR-0009) — but
 * `RegisterData::rules()` validates the address's *shape* only
 * (`emailFormatRules()`), never its uniqueness: that question is this
 * class's to answer, not the validator's, because a validation failure and
 * a notice mail must not read differently to whoever submitted the form.
 *
 * Timing is the other channel the ADR calls out: creating a real account
 * hashes a password (`User`'s `password` cast) and writes two rows before
 * the response comes back; noticing a duplicate does neither. Both branches
 * run inside `Illuminate\Support\Timebox`, the same fixed-floor mechanism
 * `Illuminate\Auth\Passwords\PasswordBroker::sendResetLink()` already uses
 * for the same reason (`GenericPasswordResetLinkResponse`'s docblock) —
 * 200ms here too, so a duplicate answers no faster than a real signup can.
 *
 * `Fortify::createUsersUsing()` registers this class with `app()->singleton()`
 * (vendor/laravel/fortify/src/Fortify.php), so it is built once per Octane
 * worker (ADR-0002/octane-development skill) and reused across every
 * subsequent request on that worker — constructor-injecting `Request` (or
 * any per-request service such as `AuthMailer`) here would cache the first
 * request that ever triggered the singleton forever. `resolveLocale()`
 * resolves the request lazily via the `request()` helper instead, and
 * `AuthMailer` is resolved at call time (`app(AuthMailer::class)`) rather
 * than injected, for the same reason (`.ai/rules/providers.md`).
 */
final class CreateNewUser implements CreatesNewUsers
{
    /**
     * Same floor `PasswordBroker::sendResetLink()` defaults to
     * ($timeboxDuration) — no reason for the two disclosure-sensitive auth
     * forms in this application to pick different numbers.
     */
    private const int TIMEBOX_MICROSECONDS = 200_000;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): ?User
    {
        $data = RegisterData::validateAndCreate($input);

        return app(Timebox::class)->call(function () use ($data): ?User {
            $existing = User::query()->where('email', $data->email)->first();

            if ($existing !== null) {
                app(AuthMailer::class)->sendRegistrationAttemptNotice($existing);

                return null;
            }

            return DB::transaction(function () use ($data): User {
                $user = User::create([
                    'name' => $data->name,
                    'email' => $data->email,
                    'password' => $data->password,
                    'username' => $data->username,
                    'locale' => $this->resolveLocale(),
                ]);

                // Identity's own contract event (SPEC.md), distinct from
                // Fortify's framework `Registered` — fired by
                // `AccountRegistrationController::store()`, once it has
                // decided a real account exists to fire it about. Consumers
                // project read models from this scalar payload (ADR-0004).
                // Registration always runs inside a resolved brand
                // (ResolveBrand fronts the web group), so currentOrFail is
                // safe here.
                event(new UserRegistered(
                    userId: $user->id,
                    brandId: app(BrandContext::class)->currentOrFail()->getKey(),
                    email: $user->email,
                    displayName: $user->name,
                    username: $user->username,
                    locale: $user->locale,
                    registeredAt: now()->toIso8601String(),
                ));

                return $user;
            });
        }, self::TIMEBOX_MICROSECONDS);
    }

    /**
     * The request's negotiated language if it sent one, otherwise the app
     * default. No brand- or user-facing locale switcher exists yet (SPEC.md
     * open questions), so this is the only signal available today.
     */
    private function resolveLocale(): string
    {
        return request()->getPreferredLanguage() ?? (string) config('app.locale');
    }
}
