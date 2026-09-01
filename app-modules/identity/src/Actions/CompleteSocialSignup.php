<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Konventix\Identity\Data\CompleteSocialSignupData;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\UsernameGenerator;
use Konventix\Identity\Social\SocialIdentityData;
use Konventix\Platform\Brands\BrandContext;

/**
 * Turn a verified provider identity plus the details it could not supply into
 * an account.
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
 * had never heard of.
 */
final class CompleteSocialSignup
{
    public function __construct(
        private readonly LinkSocialIdentity $link,
        private readonly UsernameGenerator $usernames,
    ) {}

    public function handle(CompleteSocialSignupData $data, SocialIdentityData $identity): User
    {
        return DB::transaction(function () use ($data, $identity): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => null,
                'username' => $this->usernames->generate($data->name),
                'locale' => request()->getPreferredLanguage() ?? (string) config('app.locale'),
            ]);

            $this->link->handle($user, $identity);

            event(new UserRegistered(
                userId: $user->id,
                brandId: app(BrandContext::class)->currentOrFail()->getKey(),
                email: $user->email,
                displayName: $user->name,
                locale: $user->locale,
                registeredAt: now()->toIso8601String(),
            ));

            event(new Registered($user));

            return $user;
        });
    }
}
