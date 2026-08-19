<?php

declare(strict_types=1);

namespace Konventix\Identity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * @extends Factory<SocialIdentity>
 */
class SocialIdentityFactory extends Factory
{
    /** @var class-string<SocialIdentity> */
    protected $model = SocialIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'steam',
            // A SteamID64 is a 17-digit number in the 7656119... range.
            'provider_user_id' => (string) fake()->unique()->numerify('765611980########'),
            'nickname' => fake()->userName(),
            'avatar_url' => null,
            'linked_at' => now(),
        ];
    }

    /**
     * An identity carrying no profile data — what a deployment without a Steam
     * Web API key stores (`Social\SteamProvider::profileFor()`).
     */
    public function withoutProfile(): static
    {
        return $this->state(['nickname' => null, 'avatar_url' => null]);
    }
}
