<?php

declare(strict_types=1);

namespace Konventix\Identity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;

/**
 * @extends Factory<BrandMembership>
 */
class BrandMembershipFactory extends Factory
{
    /** @var class-string<BrandMembership> */
    protected $model = BrandMembership::class;

    /**
     * `brand_id` is deliberately absent: BelongsToBrand stamps it from the
     * resolved brand context (ADR-0010).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_seen_at' => now(),
            'preferences' => null,
        ];
    }
}
