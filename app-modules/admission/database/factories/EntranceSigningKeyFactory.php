<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Models\EntranceSigningKey;

/**
 * @extends Factory<EntranceSigningKey>
 */
class EntranceSigningKeyFactory extends Factory
{
    /** @var class-string<EntranceSigningKey> */
    protected $model = EntranceSigningKey::class;

    /**
     * Generates a real Ed25519 keypair rather than fake strings: tests that
     * sign and verify would be worthless against made-up key material.
     *
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'kid' => Str::lower(Str::random(16)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'activated_at' => now(),
            'retired_at' => null,
            'expires_at' => now()->addYear(),
        ];
    }

    /**
     * Retired but not yet expired: must still verify, must not sign.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'retired_at' => now()->subDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'retired_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
    }
}
