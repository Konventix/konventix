<?php

declare(strict_types=1);

namespace Konventix\Identity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Identity\Models\Passkey;
use Konventix\Identity\Models\User;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * A stored passkey row, for tests that list, delete or sign in with one.
 *
 * The `credential` is the shape `laravel/passkeys` stores (a serialised
 * `Webauthn\CredentialRecord`), filled with placeholder bytes: nothing in
 * this module's tests runs a real WebAuthn ceremony over it — the package's
 * verification action is faked where a login is exercised (`PasskeyTest`).
 *
 * @extends Factory<Passkey>
 */
class PasskeyFactory extends Factory
{
    /**
     * @var class-string<Passkey>
     */
    protected $model = Passkey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credentialId = random_bytes(32);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['MacBook', 'Phone', 'YubiKey', 'Windows Hello']),
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => [
                'publicKeyCredentialId' => Base64UrlSafe::encodeUnpadded($credentialId),
                'type' => 'public-key',
                'transports' => ['internal'],
                'attestationType' => 'none',
                'trustPath' => ['type' => 'Webauthn\\TrustPath\\EmptyTrustPath'],
                'aaguid' => '00000000-0000-0000-0000-000000000000',
                'credentialPublicKey' => Base64UrlSafe::encodeUnpadded(random_bytes(77)),
                'userHandle' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
                'counter' => 0,
                'otherUI' => null,
                'backupEligible' => true,
                'backupStatus' => true,
                'uvInitialized' => true,
            ],
            'last_used_at' => null,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    public function lastUsed(): static
    {
        return $this->state(fn (): array => ['last_used_at' => now()->subHour()]);
    }

    /**
     * A credential id the test can hand back in a faked assertion.
     */
    public function withCredentialId(string $rawId): static
    {
        return $this->state(fn (array $attributes): array => [
            'credential_id' => Base64UrlSafe::encodeUnpadded($rawId),
            'credential' => [
                ...$attributes['credential'],
                'publicKeyCredentialId' => Base64UrlSafe::encodeUnpadded($rawId),
            ],
        ]);
    }
}
