<?php

declare(strict_types=1);

namespace Konventix\Identity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The model this factory builds. Stated explicitly because Laravel's
     * convention-based guessing only covers the `App\Models` namespace.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The current raw (unencrypted) two-factor secret being used by the
     * factory. Exposed via `twoFactorSecret()` so tests can compute a valid
     * TOTP code for a `withTwoFactor()` user without decrypting the model's
     * `two_factor_secret` column themselves.
     */
    protected static ?string $twoFactorSecret;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'username' => 'u'.Str::lower(Str::random(14)),
            'tagline' => null,
            'status_emoji' => null,
            'social_links' => null,
            'profile_visibility' => ProfileVisibility::Internal,
            'listed_in_attendees' => false,
            'locale' => 'en',
            // Null, not UTC: a fresh account has chosen no zone and reads
            // every timestamp on its browser's clock (#243).
            'timezone' => null,
            'state' => AccountState::Active,
            // Explicit nulls so the in-memory instance carries the attributes:
            // Model::shouldBeStrict() treats access to a never-set attribute
            // as an error, and Fortify's 2FA state reader touches these on
            // every Security-page render.
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the account is locked (`Actions\AuthenticateUser` refuses
     * login regardless of password).
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => AccountState::Locked,
        ]);
    }

    /**
     * Indicate that the account was closed by an operator-performed merge
     * (`Actions\MergeUserAccounts`, #81) into the given survivor — or a
     * throwaway one if the caller does not care which. Credentials cleared,
     * exactly as the Action leaves them, so `hasPassword()` reads false here
     * the same way it would after a real merge.
     */
    public function merged(?User $into = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AccountState::Merged,
            'merged_into_user_id' => ($into ?? User::factory()->create())->getKey(),
            'password' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured and
     * confirmed — mirrors what `EnableTwoFactorAuthentication` +
     * `ConfirmTwoFactorAuthentication` leave on the row (vendor/laravel/
     * fortify/src/Actions), so `hasEnabledTwoFactorAuthentication()` (which
     * `confirm: true` makes depend on `two_factor_confirmed_at`) is true.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(static::twoFactorSecret()),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(
                Collection::times(8, fn () => RecoveryCode::generate())->all(),
            )),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A real base32 secret (generated once per test run and cached, same
     * pattern as `$password`), so `app(TwoFactorAuthenticationProvider::class)
     * ->generateSecretKey()`-shaped tests can produce a code that actually
     * verifies against it.
     */
    public static function twoFactorSecret(): string
    {
        return static::$twoFactorSecret ??= app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    }
}
