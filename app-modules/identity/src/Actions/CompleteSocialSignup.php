<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Timebox;
use Konventix\Identity\Data\CompleteSocialSignupData;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Mail\AuthMailer;
use Konventix\Identity\Models\User;
use Konventix\Identity\Social\SocialIdentityData;
use Konventix\Platform\Brands\BrandContext;

/**
 * Turn a verified provider identity plus the details it could not supply into
 * an account — or, ADR-0016/GitLab #18, decide the address already belongs
 * to somebody and answer as if the account had been created anyway.
 *
 * The account is created with **no password** — that is the point of the
 * nullable column. It is reached through the provider until the person adopts
 * a password through the ordinary reset flow.
 *
 * Fires the same two events registration does, and for the same reasons:
 * Fortify's `Registered` (which sends the verification mail and records the
 * brand membership, ADR-0016) and Identity's own `UserRegistered` contract
 * event, from which three modules build their read model of people. A social
 * signup that skipped either would produce an account the rest of the system
 * had never heard of. Both are fired here, not by `SocialAuthController`,
 * because this Action is the only thing that knows whether a real account
 * exists to fire them about — `Konventix\Identity\Actions\CreateNewUser`
 * leaves them to `AccountRegistrationController` instead only because that
 * one path has to answer through Fortify's shared `RegisterResponse`
 * contract; this one does not.
 *
 * A duplicate address is refused rather than linked — Steam asserts no
 * email, so a typed match proves nothing about who typed it, and linking on
 * it would be account takeover by typing — but the refusal itself must
 * answer exactly like a fresh signup: `null` back to `SocialAuthController`,
 * which then skips `Auth::login()` but returns the identical redirect
 * (its own docblock has the detail). The existing account is told instead
 * (`AuthMailer::sendRegistrationAttemptNotice()`).
 *
 * No password hash makes this timing channel narrower than registration's
 * (a Steam account has none to hash either way), but creating the account
 * still costs a second insert (`LinkSocialIdentity::handle()`) and two
 * events the notice branch never pays for. Both branches run inside the
 * same `Timebox` floor `CreateNewUser` and `Illuminate\Auth\Passwords\
 * PasswordBroker::sendResetLink()` use (200ms), so that remaining
 * difference does not reach the response either.
 */
final class CompleteSocialSignup
{
    private const int TIMEBOX_MICROSECONDS = 200_000;

    public function __construct(private readonly LinkSocialIdentity $link) {}

    public function handle(CompleteSocialSignupData $data, SocialIdentityData $identity): ?User
    {
        return app(Timebox::class)->call(function () use ($data, $identity): ?User {
            $existing = User::query()->where('email', $data->email)->first();

            if ($existing !== null) {
                app(AuthMailer::class)->sendRegistrationAttemptNotice($existing);

                return null;
            }

            return DB::transaction(function () use ($data, $identity): User {
                $user = User::create([
                    'name' => $data->name,
                    'email' => $data->email,
                    'password' => null,
                    'username' => $data->username,
                    'locale' => request()->getPreferredLanguage() ?? (string) config('app.locale'),
                ]);

                $this->link->handle($user, $identity);

                event(new UserRegistered(
                    userId: $user->id,
                    brandId: app(BrandContext::class)->currentOrFail()->getKey(),
                    email: $user->email,
                    displayName: $user->name,
                    username: $user->username,
                    locale: $user->locale,
                    registeredAt: now()->toIso8601String(),
                ));

                event(new Registered($user));

                return $user;
            });
        }, self::TIMEBOX_MICROSECONDS);
    }
}
